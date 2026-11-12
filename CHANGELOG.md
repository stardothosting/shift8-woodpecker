# Changelog

## 1.1.0

- Renamed to "Shift8 Deployment Manager for Woodpecker CI" (slug `shift8-deployment-manager-for-woodpecker-ci`) after WordPress.org review: third-party project name moved to the end after "for", with a not-affiliated notice.

- One Shift8 → Woodpecker page with tabs: Deployment (push, schedule, connection test, activity log) and Settings. Replaces the separate Tools page.
- Schedule a deployment. Woodpecker has no delayed start, so it is a WP-Cron single event in the site timezone (1 minute to 30 days ahead, one pending schedule, authorization re-checked at run time, schedules more than 30 minutes late are skipped as "missed").
- Activity log table (`{prefix}shift8_woodpecker_log`): user, UTC time, action and pipeline for starts, schedules, schedule cancellations, scheduled runs, failures, and a "Deployment finished" line with the final status.
- Removed the pipeline status panel under the Deploy button; the result is the second activity-log line.
- Removed the Cancel Deployment button and endpoint and the "View in Woodpecker" link. (The Cancel Schedule button remains.)
- The token is read only from `SHIFT8_WOODPECKER_TOKEN` in `wp-config.php`; it is never stored in the database.
- Fixed: "Settings saved." and validation notices now display on the Shift8 page.

## 1.0.0

- Initial release: server-side Woodpecker CI manual pipeline trigger, status polling and connection test.
