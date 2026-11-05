# Architecture

```
WordPress administrator
        ↓  (AJAX: intent only, nonce + capability)
WordPress plugin
        ↓  (wp_remote_*, Bearer token, configured base URL only)
Woodpecker REST API
        ↓
manual pipeline
        ↓
existing deployment shell script
        ↓
production
```

## Components

| Class | Responsibility |
| --- | --- |
| `Config` | Resolves settings (constants override DB; token is constant-only), URL/ref normalisation, validation |
| `Api\Client` | The only code that performs HTTP; returns `Pipeline` / `WP_Error` |
| `Api\Pipeline` | Validated view of a pipeline; maps Woodpecker statuses to a stable set |
| `Deployment` | Trigger, refresh, cancel; double-click lock; persists the tracked pipeline |
| `Scheduler` | One pending delayed deployment held as a WP-Cron single event; re-checks authorization when it runs |
| `Support\ActivityLog` | Audit table: who started/scheduled/cancelled what, and when |
| `Admin\Ajax` | `deploy`, `status`, `cancel`, `schedule`, `unschedule`, `test` endpoints (capability + nonce) |
| `Admin\AdminPage` | The single Shift8 → Woodpecker page: menu, tabs (Deployment, Settings), asset loading |
| `Admin\DeploymentPage` / `SettingsPage` | Tab contents; settings registration and sanitising |
| `Support\Logger` | Opt-in logging of fixed messages |

Errors use `WP_Error` throughout; nothing throws.

## Why deployment logic is not in WordPress

The existing script handles maintenance mode, rsync, database export/import, WooCommerce order and Gravity Forms preservation, cron and cache. It runs on infrastructure with SSH and filesystem access that a web request must not have, and it has operated reliably for years. Phase 1 only replaces the orchestrator (Jenkins → Woodpecker). WordPress therefore holds a single capability: "ask Woodpecker to start the configured pipeline". Concurrency control also belongs to Woodpecker (`concurrency.limit: 1`), not to a WordPress transient.

## State

- Option `shift8_woodpecker_settings` – settings (autoload off)
- Option `shift8_woodpecker_last_pipeline` – number, raw status, timestamps, user id of the last pipeline (autoload off)
- Option `shift8_woodpecker_scheduled` – the pending schedule (timestamp, user id)
- Table `{prefix}shift8_woodpecker_log` – activity log (UTC)
- Transient `shift8_woodpecker_trigger_lock` – 10s double-click guard

## Status model

Raw statuses are normalised to `pending`, `running`, `success`, `failure`, `cancelled`, `unknown`. Unrecognised future statuses become `unknown` (polling stops, nothing breaks). Polling is browser → WordPress → Woodpecker; no PHP request stays open during a deployment.
