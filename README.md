# SEOWorkerAI Connector

WordPress plugin that runs a free site-wide SEO audit on install and unlocks ongoing automation after payment.

## Features

- Site registration and token-based authentication (`X-Site-Token`)
- Google OAuth handoff (`Connect Google` button + callback state persistence)
- Event outbox + async dispatch to SEOWorkerAI
- Push and pull action ingestion
- Async action execution via Action Scheduler (with WP-Cron fallback)
- REST endpoints for pages/media/action execution
- Local execution logs and content brief cache
- Admin pages for dashboard, logs, schedules, briefs, and settings

## Development

- Requires PHP 8.0+
- Follows strict types and WordPress coding style
- PHPUnit scaffolding included (`composer test`)

## Installation

1. Copy plugin to `wp-content/plugins/seoworkerai`.
2. Activate in WordPress admin.
3. Register the site.
4. Initial audit runs once automatically.
5. Connect Google services anytime.
