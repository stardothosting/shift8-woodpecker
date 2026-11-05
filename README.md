# Shift8 Woodpecker CI

Start and monitor a [Woodpecker CI](https://woodpecker-ci.org) deployment from the WordPress admin.

```
WordPress admin → plugin (server-side) → Woodpecker REST API → manual pipeline → existing deploy script
```

The plugin replaces a WordPress → Jenkins build-token trigger. It only starts and observes a pipeline; it contains no deployment logic (no rsync, SSH, database or WP-CLI work).

## Requirements

- WordPress 6.0+, PHP 7.4+
- Woodpecker with a user API token that can access the repository
- A workflow that accepts the `manual` event (see below)

## Installation

Copy the plugin into `wp-content/plugins/shift8-woodpecker` and activate it. It has no runtime dependencies (no `vendor/` needed).

## Configuration

The API token **must** be defined in `wp-config.php`; it is never stored in the database, shown, or editable in the admin. The other settings can also be defined as constants, which override the database value and make the field read-only.

```php
define( 'SHIFT8_WOODPECKER_URL', 'https://woodpecker.example.com' );
define( 'SHIFT8_WOODPECKER_TOKEN', '...' );
define( 'SHIFT8_WOODPECKER_REPO_ID', 123 );
define( 'SHIFT8_WOODPECKER_BRANCH', 'production' );
define( 'SHIFT8_WOODPECKER_WORKFLOW', 'wordpress-deploy' ); // optional
define( 'SHIFT8_WOODPECKER_DEBUG', true );                  // optional, logs pipeline events to the PHP log
```

Fallback: the **Settings** tab of **Shift8 → Woodpecker** (URL, repository ID, branch, optional workflow, message template with `{user}` / `{site}`, polling interval 3–60s, default 5). The page only reports whether the token constant is present. Use **Test Woodpecker Connection** (Deployment tab) to verify reachability, authentication, repository access and workflow. It never starts a pipeline.

The repository ID is the numeric ID shown in the Woodpecker repository settings (or `GET /api/repos/lookup/{owner}/{name}`).

## Woodpecker workflow

```yaml
# .woodpecker/wordpress-deploy.yaml  (or .woodpecker.yml for a single workflow)
when:
  event: manual

concurrency:
  limit: 1

steps:
  wordpress_push:
    image: bash
    commands:
      - |
        set -euo pipefail
        /bin/sh /usr/local/bin/woodpecker/woodpecker-wordpress.sh suzuki.ca
```

Serialising deployments is Woodpecker's job (`concurrency.limit`); the plugin only blocks double-clicks and refuses to start a new pipeline while the tracked one is still active.

## Usage

**Shift8 → Woodpecker** (Deployment tab, the default) shows the configured target. Configuration is on the **Settings** tab (administrators only); the Deployment tab also holds scheduling, *Test Woodpecker Connection* and the activity log. *Deploy to Production* asks for confirmation, triggers the pipeline, then polls status every few seconds until it is `success`, `failure` or `cancelled`. *Cancel Deployment* is shown while a pipeline is active. Only users with `manage_options` (filter `shift8_woodpecker_deploy_capability`) can use it.

## Scheduling

on the Deployment tab, under *Schedule a deployment*, choose a date and time (site timezone, at least 1 minute and at most 30 days ahead) and confirm. Only one schedule can be pending; cancel it to schedule another. Woodpecker cannot delay a pipeline (its API starts immediately and its cron jobs are recurring), so the plugin holds the schedule as a WP-Cron single event and triggers the same manual pipeline when it is due. Authorization is checked again at run time: if the user who scheduled it has lost the deploy capability, nothing is started.

WP-Cron only runs when WordPress is loaded. For reliable timing disable the page-load trigger and call it from system cron:

```php
define( 'DISABLE_WP_CRON', true );
```
```
* * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

A schedule that is more than 30 minutes overdue (filter `shift8_woodpecker_schedule_grace`) is skipped and logged as missed instead of deploying at a surprising time. Deactivating the plugin discards a pending schedule.

## Activity log

The Deployment tab lists the latest 50 events: date/time (site timezone), WordPress user, action (started, scheduled, schedule cancelled, scheduled run started, failed, missed, cancelled running deployment), pipeline number and details. Entries live in the `{prefix}shift8_woodpecker_log` table with UTC timestamps and a snapshot of the user login, and are removed on uninstall.

## Development

```bash
composer install
composer check   # phpcs + phpstan + phpunit
```

See [docs/](docs/) for architecture, API, security and testing notes.

## Release

`./push.sh "message" 1.0.1` pushes to git and syncs `trunk`, `assets` and a tag into `svn/` (same layout as the other Shift8 plugins). Check out the WordPress.org SVN repo into `svn/` first.

## Troubleshooting

| Message | Likely cause |
| --- | --- |
| Could not reach Woodpecker | Wrong URL, DNS/firewall, TLS problem, timeout |
| rejected the API token (401) | Token invalid/expired |
| not permitted (403) | Token lacks access to the repo |
| could not find … (404) | Wrong repository ID |
| Woodpecker did not accept the deployment | Branch/workflow does not exist, or the workflow lacks `when: event: manual` |
| Workflow "…" was not found | Name mismatch with the workflow file name on that branch |

Enable `SHIFT8_WOODPECKER_DEBUG` (or `WP_DEBUG`) to log pipeline numbers and status changes to the PHP error log.
