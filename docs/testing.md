# Testing

```bash
composer install
composer test      # PHPUnit
composer phpcs     # WordPress Coding Standards
composer phpstan   # static analysis (level 6, WordPress stubs)
composer check     # all of the above
```

## Strategy

Tests run without WordPress or network access. `tests/stubs.php` provides small in-memory doubles for the WordPress functions the plugin uses:

- `wp_remote_get/post` return queued responses and record each request; an unexpected request fails the test, so a real Woodpecker server can never be called.
- Options, transients, WP-Cron (`wp_schedule_single_event` etc.), `add_action`/`do_action`, menus and `wp_localize_script` are recorded in `Env`.
- `wpdb` is an in-memory table; `dbDelta` and `DROP TABLE` statements are captured. `tests/wp/` is a dummy `ABSPATH` so `upgrade.php` can be required.
- `wp_timezone()`/`wp_date()` honour `Env::$timezone`, so timezone and daylight-saving behaviour is testable.
- `wp_send_json_*` throw a catchable response object.
- Constants cannot be undefined, so tests that need a constant (or its absence) run in separate processes (`ConstantsTest`, `NoTokenTest`, the debug logger test). The `TestCase` defines the token constant by default.

## Organisation

- `ClientTest` – headers, endpoints, payload, workflow/branch handling, HTTP/JSON/WP_Error failures, scrubbing, connection test
- `DeploymentTest` – single trigger, persistence, lock, active-pipeline guard, status uses the stored number, success/failure/unknown
- `ActivityLogTest` – user/time/pipeline recorded for deploy, finish (once, with status) and failure; double-click not logged; ordering, sanitising
- `SchedulerTest` – limits, single pending schedule, cancel, run-time re-authorization, overdue skip, failures, AJAX validation
- `SchedulerFlowTest` – end to end: the entered local date/time becomes exactly one WP-Cron event with the right timestamp (asserted in the site timezone and in UTC, across EDT/EST/no-DST zones); stored option, response and log agree; firing the cron hook triggers the pipeline as the scheduling user; unschedule; deactivation; the cron hook is registered outside the admin
- `SecurityTest` – capability and nonce on every endpoint, ignored browser-supplied targets, token absent from responses and rendered pages
- `SettingsTest` – sanitisation, URL normalisation, repo ID validation, optional workflow, defaults, token never stored
- `ConstantsTest` / `NoTokenTest` – constant overrides; missing token; DB token ignored
- `AdminPageTest` – tabs, default tab, shared menu creation, asset scoping
- `LifecycleTest` – log table install/upgrade/drop, logger on/off, script payload has no secrets, finish recorded on tab load

## Coverage

`XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text` (needs Xdebug or PCOV). Targets are the business and security logic, not a line percentage. At the time of writing overall line coverage is about 91%, with the core classes (`Client`, `Pipeline`, `Config`, `Deployment`, `Scheduler`, `ActivityLog`, `Plugin`, `Logger`) at 94–100%.

Known gaps:

- `js/admin.js` has no automated tests (polling, confirm dialogs, rendering); verify manually.
- WordPress glue that only calls core functions is not exercised: `registerSetting()`, `menuIconStyle()`, `renderWelcome()`, `uninstall.php` and the activation/deactivation hook registration in the main file.
- The stubs approximate WordPress; behaviour that depends on real WP-Cron timing (system cron, loopback requests) and a real Woodpecker server must be checked on staging: schedule a push 2–3 minutes out and watch the activity log.
- The scheduler's grace-period logic uses the real clock, so overdue/missed cases are tested by seeding stored state rather than by waiting.
