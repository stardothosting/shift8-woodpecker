# Woodpecker API usage

All requests go to the configured base URL with `Authorization: Bearer <token>`, `Accept: application/json`, a 15s timeout and redirects disabled.

| Purpose | Request |
| --- | --- |
| Trigger | `POST /api/repos/{repo_id}/pipelines` |
| Status | `GET /api/repos/{repo_id}/pipelines/{number}` |
| Latest (client method only) | `GET /api/repos/{repo_id}/pipelines/latest` |
| Cancel | `POST /api/repos/{repo_id}/pipelines/{number}/cancel` |
| Workflows | `GET /api/repos/{repo_id}/workflows?branch={branch}` |
| Connection test | `GET /api/user`, `GET /api/repos/{repo_id}`, workflows |

## Trigger payload

```json
{ "branch": "production", "message": "WordPress production deployment by Jane", "workflow": "wordpress-deploy", "variables": {} }
```

`workflow` is omitted when not configured. Only `number` is required from the response; HTTP 200 means "accepted", not "deployed".

## Responses consumed

`number` (int > 0), `status` (string), `started`, `finished` (unix timestamps). Everything else is ignored. The workflows response may be a list of strings or objects with `name`; if the endpoint is unavailable the connection test reports that the workflow could not be verified rather than failing. A configured workflow missing from a successful list is an error.

## Scheduling (why it is not a Woodpecker feature)

Woodpecker has no equivalent of Jenkins' `delay=` quiet period. `POST /pipelines` accepts only `branch`, `variables` (and `workflow`/`message` as used here) and starts at once; repository cron jobs (`/api/repos/{id}/cron`) are recurring, run with the `cron` event rather than `manual`, and cannot be one-off. Using them would need a recurring cron entry created and deleted around each run and a different `when:` filter, so the schedule is kept in WordPress (WP-Cron) and the unchanged manual pipeline is triggered at the due time.

## Polling and cancellation

After a trigger the browser polls the plugin's `status` AJAX action every configured interval (default 5s, filter `shift8_woodpecker_poll_interval`). The plugin queries the stored pipeline number and stops when the state is not `pending`/`running`. Cancel posts to the cancel endpoint for the stored pipeline, then re-reads its status.

## Error codes (`WP_Error`)

`shift8_woodpecker_config`, `_http` (network), `_auth` (401), `_forbidden` (403), `_not_found` (404), `_trigger_rejected`, `_status_failed`, `_cancel_failed`, `_invalid_response`, `_workflow_missing`, `_locked`, `_active`, `_nothing_to_cancel`.

The Woodpecker UI link (`{base}/repos/{id}/pipeline/{n}`) is built in `Client::pipelineUrl()` only.
