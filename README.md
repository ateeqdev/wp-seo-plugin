# SEO Automation Connector

WordPress plugin that integrates with a Laravel SEO automation backend.

## Features

- Site registration and token-based authentication (`X-Site-Token`)
- OAuth handoff via Laravel (`Connect Google` button + callback state persistence)
- Event outbox + async dispatch to Laravel
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

1. Copy plugin to `wp-content/plugins/seo-automation-connector`.
2. Activate in WordPress admin.
3. Configure Laravel base URL in plugin settings.
4. Register and connect the site.
