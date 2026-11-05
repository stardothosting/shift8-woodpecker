=== Shift8 Woodpecker CI ===
Contributors: shift8
Donate link: https://www.shift8web.ca
Tags: woodpecker, ci, deployment, shift8, push
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Start or schedule a Woodpecker CI deployment pipeline from the WordPress admin, with a log of who did it and when. All API calls are made server-side.

== Description ==

Shift8 Woodpecker CI lets an administrator push a staging site to production by starting a manual Woodpecker pipeline from Shift8 → Woodpecker, either immediately or at a scheduled date and time. An activity log records the WordPress user, the date/time, and how each deployment ended.

The plugin only triggers and observes the pipeline; the deployment itself is performed by your Woodpecker workflow. The API token stays on the server, is never stored in the database and is never sent to the browser. End users cannot cancel a running deployment.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/shift8-woodpecker-ci` and activate it (on the staging site).
2. Define the API token in `wp-config.php`. It is required and is the only way to set it:

`define( 'SHIFT8_WOODPECKER_TOKEN', '...' );`

3. Open Shift8 → Woodpecker → Settings and enter the Woodpecker URL, repository ID, branch and (optionally) workflow. These can also be defined in `wp-config.php` as `SHIFT8_WOODPECKER_URL`, `SHIFT8_WOODPECKER_REPO_ID`, `SHIFT8_WOODPECKER_BRANCH` and `SHIFT8_WOODPECKER_WORKFLOW`, which override the saved values.
4. On the Deployment tab click "Test Woodpecker Connection".
5. Make sure the Woodpecker workflow accepts the `manual` event.
6. For scheduled deployments use a system cron to run `wp-cron.php` every minute and add `define( 'DISABLE_WP_CRON', true );`.

== External services ==

This plugin connects to one external service: the Woodpecker CI server whose address you enter in the settings (or define in `wp-config.php`). Woodpecker is open source and normally self-hosted, so the server is your own or one your organisation operates. Nothing is sent anywhere else, and the plugin does not contact Shift8 or any third party.

When it is used:

* When an administrator clicks "Test Woodpecker Connection", the plugin requests `/api/user`, `/api/repos/{id}` and the repository's workflow list.
* When a deployment is started (immediately or by a schedule), the plugin sends a request to `/api/repos/{id}/pipelines` containing the configured branch, the optional workflow name, an empty variables object and a short message (by default "WordPress production deployment by {display name of the WordPress user}").
* While a deployment is running, the plugin requests `/api/repos/{id}/pipelines/{number}` to read its status.

Every request carries your Woodpecker API token as a bearer token in the Authorization header. The token is read only from `wp-config.php` and is never shown in the browser or stored in the database.

Woodpecker is a project of the Woodpecker CI community: https://woodpecker-ci.org. This plugin is an independent integration and is not affiliated with or endorsed by that project. Terms and privacy for your server are those of whoever hosts it.

== Frequently Asked Questions ==

= Does the plugin deploy anything itself? =

No. It asks Woodpecker to start a pipeline. Everything else is up to your pipeline.

= How does scheduling work? =

Woodpecker cannot delay a pipeline, so the schedule is kept in WordPress (WP-Cron) and the pipeline is started when the time arrives, in the site timezone. One schedule can be pending at a time; a schedule that is more than 30 minutes late is skipped and logged instead of running.

= Who can deploy? =

Users with the `manage_options` capability by default, filterable with `shift8_woodpecker_deploy_capability`. Settings always require `manage_options`.

= Where do I see the result of a deployment? =

In the activity log on the Deployment tab: a "Started deployment" line and, when the pipeline stops, a "Deployment finished" line with the final status. It is recorded when the page next checks the pipeline, so it may appear at your next visit if nobody was watching.

= Does it work on the production site too? =

Install it on staging only. A staging-to-production database copy carries the plugin's saved settings, schedule and log with it.

== Changelog ==

= 1.1.0 =
* Single Shift8 → Woodpecker page with Deployment and Settings tabs
* Schedule a deployment for a later date/time (site timezone, up to 30 days ahead)
* Activity log of which user started or scheduled a deployment, when, and the final status
* End users cannot cancel a running deployment; removed the link to the Woodpecker UI
* The API token can only be set in wp-config.php

= 1.0.0 =
* Initial release
