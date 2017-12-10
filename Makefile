ci: lint typecheck

lint:
	vendor/bin/phpcs
typecheck:
	vendor/bin/phpstan analyse --level=max src

.PHONY: lint typecheck ci
