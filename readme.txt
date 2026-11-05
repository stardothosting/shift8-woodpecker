=== Shift8 Woodpecker CI ===
* Contributors: shift8
* Donate link: https://www.shift8web.ca
* Tags: woodpecker, ci, deployment, shift8
* Requires at least: 6.0
* Tested up to: 7.1
* Requires PHP: 7.4
* Stable tag: 1.1.0
* License: GPLv3
* License URI: http://www.gnu.org/licenses/gpl-3.0.html

Start and monitor a Woodpecker CI deployment pipeline from the WordPress admin. All API calls are made server-side.

== Description ==

Shift8 Woodpecker CI lets a privileged administrator start a manual Woodpecker pipeline from Shift8 → Woodpecker immediately or at a scheduled time, follow its status without reloading the page, and cancel it if needed. Every start, schedule and cancellation is recorded with the WordPress user and the date/time. The plugin only triggers and observes the pipeline; the deployment itself is performed by your Woodpecker workflow.

The API token stays on the server and is never sent to the browser.

== Instructions for setup ==

1. Upload the plugin to `/wp-content/plugins/shift8-woodpecker` and activate it.
2. Define the API token in `wp-config.php` (required; it is never stored in the database). The other settings may be defined there too:

`define( 'SHIFT8_WOODPECKER_URL', 'https://woodpecker.example.com' );`
`define( 'SHIFT8_WOODPECKER_TOKEN', '...' );`
`define( 'SHIFT8_WOODPECKER_REPO_ID', 123 );`
`define( 'SHIFT8_WOODPECKER_BRANCH', 'production' );`
`define( 'SHIFT8_WOODPECKER_WORKFLOW', 'wordpress-deploy' );`

3. Set the remaining values on the Settings tab of Shift8 → Woodpecker, then click "Test Woodpecker Connection" on the Deployment tab.
4. Make sure the Woodpecker workflow accepts the `manual` event.

== Frequently Asked Questions ==

= Does the plugin deploy anything itself? =

No. It asks Woodpecker to start a pipeline. Everything else is up to your pipeline.

= Who can deploy? =

Users with the `manage_options` capability by default, filterable with `shift8_woodpecker_deploy_capability`.

== Changelog ==

= 1.1.0 =
* Single Shift8 → Woodpecker page with Deployment and Settings tabs
* Schedule a deployment for a later date/time (up to 30 days ahead)
* Activity log of which user started, scheduled or cancelled a deployment, and when

= 1.0.0 =
* Initial release
