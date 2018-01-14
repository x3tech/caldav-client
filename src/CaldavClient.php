<?php
namespace x3tech\CaldavClient;

use Sabre\Xml;
use Sabre\DAV;
use Sabre\HTTP;

use Sabre\VObject;
use Sabre\VObject\Property\ICalendar;

use DOMDocument;

class CaldavClient
{
    /** @var string **/
    private $baseUrl;
    /** @var string **/
    private $user;
    /** @var string **/
    private $password;
    /** @var string **/
    private $calendarUrl;

    /** @var DAV\Client */
    protected $client;
    /** @var Xml\Service */
    protected $xml;

    public const CALDAV_NS = 'urn:ietf:params:xml:ns:caldav';
    private const CALENDAR_COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
    private const CALENDAR_TYPE = '{urn:ietf:params:xml:ns:caldav}calendar';

    public function __construct(string $baseUrl, string $user, string $password)
    {
        $this->baseUrl = $baseUrl;
        $this->user = $user;
        $this->password = $password;

        $this->xml = new Xml\Service;
        $this->xml->namespaceMap = [
            'DAV:' => 'd',
            self::CALDAV_NS => 'c',
        ];

        $this->client = new DAV\Client([
            'baseUri' => $baseUrl,
            'userName' => $user,
            'password' => $password,
        ]);
    }

    /**
     * Connect to the server and check whether it's a CalDAV server
     */
    public function init() : void
    {
        $options = $this->client->options();

        if (!in_array('calendar-access', $options)) {
            throw new \ErrorException('Not a CalDAV server (No calendar-access)');
        }

        $this->calendarUrl = $this->getCalendarUrl();
    }

    protected function getCalendarUrl() : string
    {
        $userUrl = $this->propFind($this->baseUrl, [
            '{DAV:}current-user-principal',
        ])['{DAV:}current-user-principal'][0]['value'];

        return $this->client->propFind($userUrl, [
            '{urn:ietf:params:xml:ns:caldav}calendar-home-set',
        ])['{urn:ietf:params:xml:ns:caldav}calendar-home-set'][0]['value'];
    }

    /**
     * Get an array of calendar
     *
     * @return Calendar[]
     */
    public function getCalendars() : array
    {
        $calendar = $this->propFind($this->calendarUrl, [
            '{DAV:}resourcetype',
            '{DAV:}displayname',
            self::CALENDAR_COMPONENTS,
        ], [], 1);

        $calendars = [];
        foreach ($calendar as $url => $calendar) {
            if (!$calendar['{DAV:}resourcetype']->is(self::CALENDAR_TYPE)) {
                continue;
            }

            $calendars[$calendar['{DAV:}displayname']] = new Calendar(
                $this,
                $calendar['{DAV:}displayname'],
                $url,
                [] // TODO, properly pass self::CALENDAR_COMPONENTS
            );
        }

        return $calendars;
    }

    protected function addFilters(array $filters)
    {
        $result = [];
        foreach ($filters as $key => $filter) {
            // For now, assume uppercase keys are comp-filter nodes
            if ($key == strtoupper($key)) {
                $result['c:comp-filter'] = [
                    'attributes' => [
                        'name' => $key,
                    ],
                    'value' => $this->addFilters($filter),
                ];
            } else {
                $result[$key]['attributes'] = $filter;
            }
        }
        return $result;
    }

    public function report(
        string $url,
        string $root,
        array $props,
        array $filters = [],
        int $depth = 0
    ) : array {
        return $this->davRequest($url, 'REPORT', $root, $props, $filters, $depth);
    }

    /**
     * Does a PROPFIND request
     *
     * The list of requested properties must be specified as an array, in clark
     * notation.
     *
     * The returned array will contain a list of filenames as keys, and
     * properties as values.
     *
     * The properties array will contain the list of properties. Only properties
     * that are actually returned from the server (without error) will be
     * returned, anything else is discarded.
     *
     * Depth should be either 0 or 1. A depth of 1 will cause a request to be
     * made to the server to also return all child resources.
     *
     * @param string $url
     * @param array $props
     * @param int $depth
     * @return array
     */
    public function propFind(
        string $url,
        array $props,
        array $filters = [],
        int $depth = 0
    ) {
        return $this->davRequest($url, 'PROPFIND', 'd:propfind', $props, $filters, $depth);
    }

    protected function parseDavResponse($response, $depth) : array
    {
        if ((int)$response->getStatus() >= 400) {
            throw new \Exception('HTTP error: ' . $response->getStatus());
        }

        $result = $this->client->parseMultiStatus($response->getBodyAsString());

        // If depth was 0, we only return the top item
        if ($depth === 0) {
            $result = reset($result);
            return $result[200] ?? [];
        }

        $newResult = [];
        foreach ($result as $href => $statusList) {
            $newResult[$href] = $statusList[200] ?? [];
        }

        return $newResult;
    }

    protected function davRequest(
        string $url,
        string $method,
        string $root,
        array $props,
        array $filters = [],
        int $depth = 0
    ) : array {
        $xml = [
            'd:prop' => array_map(function ($p) {
                return ['name' => $p];
            }, $props),
        ];
        if (count($filters)) {
            $xml['c:filter'] = $this->addFilters($filters);
        }

        $url = $this->client->getAbsoluteUrl($url);
        $body = $this->xml->write($root, $xml);

        $request = new HTTP\Request($method, $url, [
            'Depth' => $depth,
            'Content-Type' => 'application/xml'
        ], $body);

        $response = $this->client->send($request);
        return $this->parseDavResponse($response, $depth);
    }

    /**
     * Convert a Component to a more easily readable/usable associative array
     */
    public static function objectToArray(VObject\Component $in) : array
    {
        $result = [];
        foreach ($in->children() as $child) {
            if ($child instanceof VObject\Component) {
                $val = self::objectToArray($child);
            } elseif ($child instanceof ICalendar\DateTime) {
                $val = $child->getDateTime();
            } elseif ($child instanceof ICalendar\Recur) {
                $val = $child->getJsonValue();
            } elseif ($child instanceof ICalendar\Duration) {
                $val = $child->getDateInterval();
            } elseif ($child instanceof ICalendar\CalAddress) {
                $val = $child->getNormalizedValue();
            } else {
                if (count($child->parameters)) {
                    echo get_class($child) . "\n";
                    $val = [
                        'value' => $child->getValue(),
                    ];

                    foreach ($child->parameters as $parameter) {
                        $val[$parameter->name] = $parameter->getValue();
                    }
                    print_r($val);
                } else {
                    $val = $child->getValue();
                }
            }

            // Can have multiple of the same, first one's the charm for now
            if (!array_key_exists($child->name, $result)) {
                $result[$child->name] = $val;
            }
        }
        return $result;
    }
}
