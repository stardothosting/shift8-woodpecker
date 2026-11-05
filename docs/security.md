# Security

## Token handling

- Sole source: `SHIFT8_WOODPECKER_TOKEN` in `wp-config.php`. There is no database fallback and no settings field, so the token cannot appear in database dumps, backups, option exports, or the staging-to-production database copy the deploy script performs, and a `manage_options` user cannot read or replace it from the UI. A token placed in the options table is ignored.
- Encrypting a database-stored token was rejected: the key would live alongside it, adding complexity without a real benefit.
- Only `Api\Client` reads the token. It is never rendered (the settings page only says whether the constant exists), localized to JS, put in data attributes, returned by AJAX, logged, or included in errors (remote error text is stripped of markup, truncated to 200 chars and the token is redacted).
- Use a dedicated Woodpecker user/token with access to this repository only.

## Capability model

Deploy, status and cancel require `shift8_woodpecker_deploy_capability` (default `manage_options`). Settings and the connection test always require `manage_options`. Checks run server-side on every request and page render, not just by hiding buttons.

## CSRF

Every AJAX action verifies the `shift8_woodpecker_ajax` nonce after the capability check. The settings form uses the Settings API nonce.

## Browser/server trust boundary

The browser sends only an intent (`deploy`, `status`, `cancel`, `test`) plus the nonce. URL, repository ID, branch, workflow and pipeline number come from server configuration or stored state, never from the request. This prevents arbitrary repositories, branches, workflows or pipelines being targeted, and removes SSRF: requests only go to the configured base URL (http/https only, no credentials, redirects disabled so the token cannot follow a redirect to another host).

## Scheduling and audit

`schedule` and `unschedule` use the same capability and nonce checks. The browser supplies only a date/time, validated server-side (strict format, 1 minute to 30 days ahead). The scheduling user is taken from the session, never the request. When the schedule fires there is no logged-in user, so the plugin re-checks that the stored user still has the deploy capability. The activity log stores user id, a login snapshot, UTC time, event, pipeline number and a short sanitized detail (error text is scrubbed and never contains the token); it is rendered with `textContent`.

## Duplicate protection

A 10s transient stops double-clicks, and a new deployment is refused while the tracked pipeline is pending/running. Authoritative serialisation is Woodpecker's `concurrency.limit`.

## Untrusted responses

Woodpecker data is validated (`number` must be a positive integer, status reduced to `[a-z_]`) and rendered with `textContent` in JS and `esc_*` in PHP.

## Review checklist (answered)

1. Unprivileged deploy? No – capability check. 2. CSRF? No – nonce. 3. Token in JS? No. 4. Token in errors? No – redacted. 5. Token in logs? No – logs contain numbers/status only. 6–7. Browser-supplied repo/branch/workflow? Ignored. 8. SSRF? Only configured base URL. 9. Rapid clicks? Lock + active-pipeline check. 10. Untrusted responses? Validated and escaped.
