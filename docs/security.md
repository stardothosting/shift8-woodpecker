# Security

## Token handling

- Sole source: `SHIFT8_WOODPECKER_TOKEN` in `wp-config.php`. No database fallback, no settings field, so the token cannot appear in database dumps, backups, option exports, or the staging-to-production database copy, and a `manage_options` user cannot read or replace it from the UI. A token placed in the options table is ignored.
- Encrypting a database-stored token was rejected: the key would live alongside it.
- Only `Api\Client` reads the token. It is never rendered (the settings tab only says whether the constant exists), localized to JS, put in data attributes, returned by AJAX, logged, stored in the activity log, or included in errors (remote error text is stripped of markup, truncated and the token redacted).
- Use a dedicated Woodpecker user/token with access to this repository only.

## Capability model

- Deploy, status, schedule and unschedule: `shift8_woodpecker_deploy_capability` (default `manage_options`).
- Settings and the connection test: always `manage_options`.
- Checks run server-side on every AJAX request and every page render, not by hiding buttons. The Settings tab is hidden from, and refused to, users without `manage_options`.

## CSRF

Every AJAX action verifies the `shift8_woodpecker_ajax` nonce after the capability check. The settings form uses the Settings API nonce. Tab selection (`?tab=`) is whitelisted and not a state change.

## Browser/server trust boundary

The browser sends only an intent (`deploy`, `status`, `schedule`, `unschedule`, `test`), the nonce and, for scheduling, a date/time string. URL, repository ID, branch, workflow, pipeline number and the acting user come from server configuration, stored state or the session, never from the request. This prevents arbitrary repositories, branches, workflows or pipelines being targeted and removes SSRF: requests only go to the configured base URL (http/https only, no credentials; redirects disabled so the token cannot follow a redirect to another host).

## Scheduling and audit

- The date/time is validated strictly (`YYYY-MM-DDTHH:MM`), interpreted in the site timezone, and must be 1 minute to 30 days ahead. One schedule at a time.
- When the WP-Cron event fires there is no logged-in user. The plugin re-checks that the stored user still has the deploy capability, ignores events whose timestamp no longer matches the stored schedule (cancelled/replaced), and skips schedules more than 30 minutes overdue.
- The activity log stores user id, a login snapshot, UTC time, event, pipeline number and a short sanitized detail (scrubbed error text or final status). It is rendered with `textContent`.
- The log table and options are removed on uninstall.

## Duplicate protection

A 10 s transient stops double-clicks, and a new deployment is refused while the tracked pipeline is pending/running. Authoritative serialisation is Woodpecker's `concurrency.limit`.

## Untrusted responses

Woodpecker data is validated (`number` must be a positive integer, status reduced to `[a-z_]`, unknown statuses become `unknown`) and rendered with `textContent` in JS and `esc_*` in PHP.

## Deployment-environment risks

- The deploy script copies the staging database to production, carrying plugin settings, schedule and log with it. Keep the plugin active on staging only (see the README); this has not been verified against the production deploy script.
- Anyone able to edit `wp-config.php` or read the server environment can read the token; scope the Woodpecker token accordingly.

## Review checklist (answered)

1. Unprivileged deploy? No – capability check. 2. CSRF? No – nonce. 3. Token in JS? No. 4. Token in errors? No – redacted. 5. Token in logs (PHP or activity log)? No. 6–7. Browser-supplied repo/branch/workflow? Ignored. 8. SSRF? Only the configured base URL, no redirects. 9. Rapid clicks? Lock + active-pipeline check. 10. Untrusted responses? Validated and escaped. 11. Scheduled run by a de-privileged user? Re-checked, refused. 12. Can an end user cancel a deployment? No – the endpoint does not exist.
