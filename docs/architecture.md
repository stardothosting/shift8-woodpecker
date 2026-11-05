# Architecture

```
WordPress administrator
        ↓  (AJAX: intent only, nonce + capability)
WordPress plugin ── schedule held as a WP-Cron single event
        ↓  (wp_remote_*, Bearer token, configured base URL only)
Woodpecker REST API
        ↓
manual pipeline
        ↓
existing deployment shell script
        ↓
production
```

## Why deployment logic is not in WordPress

The existing script handles maintenance mode, rsync, database export/import, WooCommerce order and Gravity Forms preservation, cron and cache. It needs SSH and filesystem access a web request must not have, and it has run reliably for years. Phase 1 only replaces the orchestrator (Jenkins → Woodpecker). WordPress holds one capability: "ask Woodpecker to start the configured pipeline". Concurrency control also belongs to Woodpecker (`concurrency.limit: 1`), not to a WordPress transient.

## Components

| Class | Responsibility |
| --- | --- |
| `Config` | Resolves settings (constants override DB; the token is constant-only), URL/ref normalisation, validation |
| `Api\Client` | The only code that performs HTTP; returns `Pipeline` / `WP_Error` |
| `Api\Pipeline` | Validated view of a pipeline; maps Woodpecker statuses to a stable set |
| `Deployment` | Trigger and refresh; double-click lock; active-pipeline guard; persists the tracked pipeline; logs start/finish |
| `Scheduler` | One pending delayed deployment as a WP-Cron single event; re-checks authorization at run time |
| `Support\ActivityLog` | Audit table: who started/scheduled what, and when |
| `Support\Logger` | Opt-in logging of fixed messages |
| `Admin\AdminPage` | The single Shift8 → Woodpecker page: shared menu, tabs, asset loading |
| `Admin\DeploymentPage` / `SettingsPage` | Tab contents; settings registration and sanitising |
| `Admin\Ajax` | `deploy`, `status`, `schedule`, `unschedule`, `test` (capability + nonce) |
| `Plugin` | Wiring; the scheduler hook is registered outside the admin because WP-Cron is not an admin request |

Errors use `WP_Error` throughout; nothing throws.

## Decisions and lessons learned

- **Token only in `wp-config.php`.** Encrypting a database copy was rejected: the key would sit beside it. Constant-only also keeps the token out of the staging→production database copy and away from admins using the UI.
- **No Woodpecker delay → WP-Cron.** `POST /pipelines` starts at once and Woodpecker cron jobs are recurring with the `cron` event. See [woodpecker-api.md](woodpecker-api.md).
- **Scheduling is authorized twice**: when scheduled (capability + nonce) and when it fires (stored user re-checked).
- **Overdue schedules are skipped**, not run late, so an outage cannot cause a surprise production deployment.
- **No cancel, no Woodpecker link** for end users by product decision; the cancel endpoint and API method were removed rather than hidden.
- **Finish is logged from observed state**, once, when the stored status leaves pending/running. Refreshing happens when polling and on each Deployment-tab load; there is no background poller.
- **Settings live outside the Settings menu**, so `settings_errors()` is called explicitly or "Settings saved." never shows.
- **Plugin constants are namespaced** `SHIFT8_WOODPECKER_PLUGIN_*` so they cannot clash with the `SHIFT8_WOODPECKER_URL` configuration constants.
- **Log in a table** (not an option) so it is never truncated and rows cannot race; UTC timestamps plus a snapshot of the user login.
- **Menu**: the top-level `shift8-settings` menu is shared; whichever Shift8 plugin loads first creates it.

## State

- Option `shift8_woodpecker_settings` – settings (autoload off; never the token)
- Option `shift8_woodpecker_last_pipeline` – number, raw status, timestamps, user id (autoload off)
- Option `shift8_woodpecker_scheduled` – the pending schedule (timestamp, user id)
- Option `shift8_woodpecker_db_version` – log table version
- Table `{prefix}shift8_woodpecker_log` – activity log (UTC)
- WP-Cron event `shift8_woodpecker_run_scheduled` (arg: the timestamp, so stale or cancelled events are ignored)
- Transient `shift8_woodpecker_trigger_lock` – 10 s double-click guard

Uninstall removes all of it; deactivation removes the pending schedule.

## Status model

Raw statuses become `pending`, `running`, `success`, `failure`, `cancelled`, `unknown`. Unrecognised future statuses become `unknown` (treated as not active, so polling stops and nothing breaks). Polling is browser → WordPress → Woodpecker; no PHP request stays open during a deployment.
