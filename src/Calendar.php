<?php

namespace x3tech\CaldavClient;

use Sabre\VObject;

use DateInterval;
use DateTimeInterface;
use DateTimeImmutable;

class Calendar
{
    /** @var string */
    public $name;
    /** @var string */
    public $url;
    /** @var string[] */
    public $components;

    /** @var CaldavClient */
    protected $dav;

    public function __construct(
        CaldavClient $dav,
        string $name,
        string $url,
        array $components = []
    ) {
        $this->dav = $dav;
        $this->name = $name;
        $this->url = $url;
        $this->components = $components;
    }

    public function getEvents(
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null
    ) : array {
        $filters = [];
        if ($start) {
            $filters['VCALENDAR']['VEVENT']['c:time-range']['start'] = $start->format('Ymd\THis\Z');
        }
        if ($end) {
            $filters['VCALENDAR']['VEVENT']['c:time-range']['end'] = $end->format('Ymd\THis\Z');
        }

        $result = $this->dav->report($this->url, 'c:calendar-query', [
            'c:calendar-data',
        ], $filters, 1);

        $events = [];
        foreach ($result as $url => $data) {
            $raw = $data[sprintf('{%s}calendar-data', CaldavClient::CALDAV_NS)] ?? null;
            if (!$raw) {
                continue;
            }

            $events[] = CaldavClient::objectToArray(
                VObject\Reader::read($raw, VObject\Reader::OPTION_FORGIVING)
            );
        }

        return $events;
    }

    public function getTodaysEvents() : array
    {
        $today = new DateTimeImmutable('now');

        $start = $today->setTime(0, 0, 0);
        $end = $today->setTime(23, 59, 59);

        return $this->getEvents($start, $end);
    }

    public function getFutureEvents(?DateInterval $timespan = null) : array
    {
        $start = new DateTimeImmutable('tomorrow');
        $end = $timespan ? $start->add($timespan) : null;

        return $this->getEvents($start, $end);
    }
}
