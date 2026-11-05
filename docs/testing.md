# Testing

```bash
composer install
composer test      # PHPUnit
composer phpcs     # WordPress Coding Standards
composer phpstan   # static analysis (level 6, WordPress stubs)
composer check     # all of the above
```

## Strategy

Tests run without WordPress or network access. `tests/stubs.php` provides small in-memory doubles for the WordPress functions the plugin uses (`wp_remote_get/post` return queued responses and record each request; options/transients are arrays; `wp_send_json_*` throw a catchable response). A test that triggers an unexpected HTTP request fails, so a real Woodpecker server can never be called.

## Organisation

- `ClientTest` – headers, endpoints, payload, workflow/branch handling, HTTP/JSON/WP_Error failures, scrubbing, connection test
- `DeploymentTest` – single trigger, persistence, lock, active-pipeline guard, status/cancel use the stored number, success/failure/unknown states
- `SecurityTest` – capability, nonce, ignored browser-supplied targets, token absent from responses and rendered pages
- `SettingsTest` – sanitisation, URL normalisation, repo ID validation, optional workflow, defaults
- `SchedulerTest` – scheduling limits, single pending schedule, cancel, run-time re-authorization, overdue skip, failures, timezone parsing, AJAX validation
- `ActivityLogTest` – user/time/pipeline recorded for deploy, failure, cancel; double-click not logged; ordering, sanitising
- `ConstantsTest` – constant overrides (separate processes, since constants cannot be undefined)

Coverage targets the business and security logic rather than a line percentage; run `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` if Xdebug/PCOV is installed.
