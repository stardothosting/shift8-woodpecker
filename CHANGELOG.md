# Changelog

## 1.1.0

- All screens now live on one Shift8 → Woodpecker page with tabs: Deployment (push, schedule, connection test, log) and Settings. The separate Tools page was removed.
- Schedule a deployment (WP-Cron single event; Woodpecker has no delayed start). One pending schedule at a time, cancellable.
- Activity log table recording user, UTC timestamp, action and pipeline for deployments, schedules, cancellations and failures.

## 1.0.0

- Initial release: server-side Woodpecker CI manual pipeline trigger, status polling, cancellation and connection test.
