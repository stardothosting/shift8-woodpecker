# Woodpecker API usage

All requests go to the configured base URL with `Authorization: Bearer <token>`, `Accept: application/json`, a 15 s timeout and redirects disabled.

| Purpose | Request |
| --- | --- |
| Trigger | `POST /api/repos/{repo_id}/pipelines` |
| Status | `GET /api/repos/{repo_id}/pipelines/{number}` |
| Latest (client method only) | `GET /api/repos/{repo_id}/pipelines/latest` |
| Workflows | `GET /api/repos/{repo_id}/workflows?branch={branch}` |
| Connection test | `GET /api/user`, `GET /api/repos/{repo_id}`, workflows |

## Trigger payload

```json
{ "branch": "production", "message": "WordPress production deployment by Jane", "workflow": "wordpress-deploy", "variables": {} }
```

`workflow` is omitted when not configured. `variables` is an empty JSON object. Only `number` is required from the response; HTTP 200 means "accepted", not "deployed".

## Responses consumed

`number` (int > 0), `status` (string), `started`, `finished` (unix timestamps). Everything else is ignored. The workflows response may be a list of strings or of objects with `name`; if the endpoint is unavailable the connection test reports that the workflow could not be verified rather than failing. A configured workflow missing from a successful list is an error.

## Scheduling: why it is not a Woodpecker feature

Jenkins accepts `delay=` (quiet period). Woodpecker has no equivalent: `POST /pipelines` starts at once, and repository cron jobs (`/api/repos/{id}/cron`) are recurring, run with the `cron` event rather than `manual`, and cannot be one-off. Using them would need a recurring entry created and deleted around each run plus a different `when:` filter. The schedule is therefore kept in WordPress (WP-Cron) and the unchanged manual pipeline is triggered at the due time.

## Polling

After a trigger the browser polls the plugin's `status` AJAX action every configured interval (default 5 s, filter `shift8_woodpecker_poll_interval`, clamped to 3–60 s). The plugin queries the stored pipeline number and stops when the state is not `pending`/`running`; at that point the *Deployment finished* log line is written once.

There is deliberately no cancel action: end users cannot stop a running deployment from WordPress. The UI link to Woodpecker was also removed. Cancel in Woodpecker if needed.

## Error codes (`WP_Error`)

`shift8_woodpecker_config`, `_http` (network), `_auth` (401), `_forbidden` (403), `_not_found` (404), `_http_status` (other non-2xx), `_trigger_rejected`, `_status_failed`, `_invalid_response`, `_workflow_missing`, `_locked`, `_active`, `_schedule_invalid`, `_schedule_past`, `_schedule_far`, `_schedule_exists`, `_schedule_failed`, `_nothing_scheduled`.

Remote error text is stripped of markup, truncated to 200 characters and has the token redacted before it is shown or logged.
