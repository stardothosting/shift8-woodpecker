# Shift8 Woodpecker CI

Start, schedule and monitor a [Woodpecker CI](https://woodpecker-ci.org) deployment from the WordPress admin. It replaces the WordPress → Jenkins build-token trigger of [shift8-jenkins](https://github.com/stardothosting/shift8-jenkins).

```
WordPress admin → plugin (server-side) → Woodpecker REST API → manual pipeline → existing deploy script
```

The plugin only starts and observes a pipeline. It contains no deployment logic (no rsync, SSH, database copy, WP-CLI or maintenance mode); that stays in your existing deploy script, which is not touched.

## What it does

- **Push immediately** and **schedule a push** for a later date/time.
- **Activity log** of which WordPress user started or scheduled a deployment, when, and how it ended.
- Server-side only: the Woodpecker token never reaches the browser.
- End users **cannot cancel** a running deployment and there is no link to the Woodpecker UI (deliberate; stop pipelines in Woodpecker if needed).

## Requirements

- WordPress 6.0+, PHP 7.4+
- A Woodpecker user API token that can access the repository
- A workflow that accepts the `manual` event (below)
- For scheduling: a working WP-Cron (system cron recommended, below)

## Installation

Copy the plugin to `wp-content/plugins/shift8-woodpecker-ci` and activate it. There are no runtime dependencies (`vendor/` is development-only and is not shipped). Activation creates the activity-log table `{prefix}shift8_woodpecker_log`.

Install and activate it on the **staging** site only. See *Staging → production caveats* below.

## Configuration

### Token: `wp-config.php` only

```php
define( 'SHIFT8_WOODPECKER_TOKEN', '...' );
```

The token cannot be entered in the admin and is never stored in the database, so it does not appear in backups, exports, or the staging database copy. A token placed in the options table is ignored. Use a dedicated Woodpecker user/token with access to this repository only.

### Everything else: Settings tab or constants

Shift8 → Woodpecker → **Settings** (administrators only): Woodpecker URL, repository ID, branch, optional workflow, deployment message template (`{user}`, `{site}`), polling interval (3–60 s, default 5). Any of the first four can also be defined in `wp-config.php`; a constant overrides the database value and the field becomes read-only:

```php
define( 'SHIFT8_WOODPECKER_URL', 'https://woodpecker.example.com' );
define( 'SHIFT8_WOODPECKER_REPO_ID', 123 );
define( 'SHIFT8_WOODPECKER_BRANCH', 'production' );
define( 'SHIFT8_WOODPECKER_WORKFLOW', 'wordpress-deploy' ); // optional
define( 'SHIFT8_WOODPECKER_DEBUG', true );                  // optional: log pipeline events to the PHP error log
```

Invalid values are rejected with a notice and the previous value is kept (URL must be http(s) without credentials; repository ID a positive integer; branch/workflow limited to letters, digits, `.`, `_`, `-`, `/`). The repository ID is the number shown in the Woodpecker repository settings.

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

Serialising deployments is Woodpecker's job (`concurrency.limit`). The plugin only suppresses double-clicks (10 s lock) and refuses to start another deployment while the last one it started is still pending/running.

## Usage

Everything is on one page, **Shift8 → Woodpecker**, with tabs (same layout as the other Shift8 plugins).

**Deployment tab (default)**
1. *Deploy to Production* shows the configured target, asks for confirmation, then triggers the pipeline. The button stays disabled while it runs.
2. *Schedule a deployment*: pick a date/time and confirm. A pending schedule is listed with a *Cancel Schedule* button.
3. *Test Woodpecker Connection* (administrators): checks reachability, the token, repository access and the workflow. It never starts a pipeline.
4. *Activity log*: the latest 50 events. A deployment produces *Started deployment* (with the pipeline number) and later *Deployment finished* with the final status (`success`, `failure`, `cancelled`, ...).

**Settings tab**: configuration only.

The deploy capability defaults to `manage_options` and is filterable with `shift8_woodpecker_deploy_capability`. Settings and the connection test always require `manage_options`.

## Scheduling

- **Woodpecker has no delayed start** (unlike Jenkins' `delay=`). Its pipeline API starts immediately and its repository cron jobs are recurring and use the `cron` event. So the schedule is held in WordPress as a **WP-Cron single event** (`shift8_woodpecker_run_scheduled`), and the same manual pipeline is triggered when it is due.
- The date/time is read in the **site timezone** (Settings → General) and converted correctly across daylight-saving changes. The pending-schedule line and the log show site time; the log table stores UTC.
- At least 1 minute and at most 30 days ahead. **One** schedule can be pending; cancel it to change it.
- When it fires there is no logged-in user, so the plugin re-checks that the user who scheduled it still holds the deploy capability; otherwise nothing runs and it is logged.
- A schedule more than 30 minutes late (filter `shift8_woodpecker_schedule_grace`) is skipped and logged as *missed* rather than deploying at a surprising time.
- Deactivating the plugin discards a pending schedule.

### Make WP-Cron reliable

WP-Cron only runs when WordPress is loaded. Use system cron and disable the page-load trigger:

```php
define( 'DISABLE_WP_CRON', true );
```

```
* * * * * /usr/bin/php -d display_errors=on /var/www/staging.example.com/public_html/wp-cron.php >> /var/log/nginx/wordpress_cron.log 2>&1
```

The scheduled call runs in the CLI process, so that PHP must read the site's `wp-config.php` (same constants/database) and be able to reach Woodpecker. Check a pending event with `wp cron event list --fields=hook,next_run_relative` (hook `shift8_woodpecker_run_scheduled`).

## Staging → production caveats

The deploy script copies the staging database to production, so plugin data stored in the database travels with it: settings, the last-pipeline option, the pending schedule and the activity-log table. Recommendations:

- Keep the plugin active only on staging and define the connection in `wp-config.php` (not copied).
- Do not rely on the log table surviving on production; consider excluding `{prefix}shift8_woodpecker_log` and the `shift8_woodpecker_*` options from the database sync. This has **not** been verified against the production deploy script.
- Do not activate the plugin on production with the same constants, or it could trigger deployments from there.

## Limitations

- The finish of a deployment is recorded when the pipeline status is next read: by the open Deployment tab (polling) or when the tab is next loaded. If nobody is watching, the *Deployment finished* line appears at the next visit, with that time.
- Only the last started pipeline is tracked.
- One pending schedule at a time.

## Development

```bash
composer install
composer check   # phpcs (WordPress standards) + phpstan (level 6) + phpunit
```

Tests need no WordPress install and never contact a real server. See [docs/testing.md](docs/testing.md). Architecture, API usage and security notes are in [docs/](docs/). PHPStan is pinned to 1.12 (the WordPress extension used requires it), so it prints an upgrade notice.

## Release

`./push.sh "message" 1.1.0` follows the other Shift8 plugins: commits and pushes to git (`master`), then syncs `trunk`, `assets` and a version tag into `svn/` and commits with `svn ci`. It checks that the plugin header `Version` and the `readme.txt` `Stable tag` match the version, and `push.exclude` keeps `tests/`, `docs/`, `vendor/`, `composer.*`, the phpcs/phpstan/phpunit configs and repository-only files out of the WordPress.org package. Check out the WordPress.org SVN repository into `svn/` first. The icons and banners in `assets/` are the shared Shift8 artwork.

When bumping the version, update `shift8-woodpecker.php` (header and `SHIFT8_WOODPECKER_PLUGIN_VERSION`), `readme.txt` (Stable tag, changelog) and `CHANGELOG.md`.

**WordPress.org slug:** the directory assigned the slug `shift8-woodpecker-ci`, so the `Text Domain` header and every translation call use `shift8-woodpecker-ci` (the automated scan rejects a mismatch) and the submission zip's top-level folder is `shift8-woodpecker-ci/`. Internal identifiers (menu slug, option names, constants, handles) keep the `shift8-woodpecker` / `shift8_woodpecker` prefix. If the slug is ever changed again, update the text domain in `shift8-woodpecker.php`, `src/` and `phpcs.xml.dist`.

## Troubleshooting

| Message / symptom | Likely cause |
| --- | --- |
| Woodpecker is not fully configured | A required value is missing; the message names it (the token constant included) |
| Could not reach Woodpecker | Wrong URL, DNS/firewall, TLS problem, timeout (15 s) |
| rejected the API token (401) | Token invalid or expired |
| not permitted (403) | Token lacks access to the repository |
| could not find … (404) | Wrong repository ID |
| Woodpecker did not accept the deployment | Branch/workflow does not exist, or the workflow lacks `when: event: manual` |
| Workflow "…" was not found | Name differs from the workflow file name on that branch |
| Could not verify workflow | The Woodpecker version/branch does not expose the workflow list; not an error |
| Pipeline #N is still running | The previous deployment has not finished; wait for it |
| Scheduled deployment missed | WP-Cron ran more than 30 minutes late (cron stopped or server down) |
| Schedule is "overdue" | WP-Cron has not run; check the system cron entry |

Set `SHIFT8_WOODPECKER_DEBUG` (or `WP_DEBUG`) to log pipeline numbers and status changes to the PHP error log (never credentials).
