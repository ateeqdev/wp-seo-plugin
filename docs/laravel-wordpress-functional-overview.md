# SEOWorkerAI Platform Functional Documentation (Laravel + WordPress)

This document describes the full end-to-end behavior of the system across:
- WordPress plugin repository: `/Users/ateeq/seoworkerai`
- Laravel backend repository: `/Users/ateeq/seo/app.seoworkerai.com`

It covers installation, registration, ownership proof, Google OAuth, event processing, on-page optimization, action dispatch/execution, rollback/edit flows, scheduling, content briefs, admin operations, and persistence.

## 1. High-Level Architecture

- WordPress plugin is the edge agent running inside the customer site.
- Laravel application is the orchestration/control plane.
- Communication model is hybrid:
  - WP -> Laravel push: registration, health, event delivery, action status acks, user/brief sync.
  - WP <- Laravel pull: WP polls for pending actions and executes them.
- Trust boundary:
  - Site API token (`api_key` / `X-Site-Token`) authenticates site-to-backend calls.
  - Ownership challenge proof is required for registration/deactivation/reactivation flows.

## 2. Core Lifecycle: Start to End

### 2.1 Plugin bootstrap (WordPress)

On `plugins_loaded`, plugin bootstraps in this order:
1. Creates/upgrades WP plugin tables.
2. Persists Laravel base URL option.
3. Registers service container dependencies.
4. Registers hooks for:
- REST endpoints
- queue/scheduler
- event collection
- admin menu/pages
- runtime handlers

Primary wiring lives in:
- `seoworkerai.php`
- `includes/class-plugin.php`

### 2.2 Site registration flow

#### 2.2.1 WordPress side

`SiteRegistrar::registerOrUpdate()` builds payload:
- `domain`, `platform=wordpress`, `platform_version`, timezone
- admin email + mapped site users
- site profile (`description`, `taste`)
- API key if already stored

Behavior:
- Existing `site_id` + token: site-scoped update path.
- New/unknown site: requests ownership challenge first, stores challenge token locally, sends proof URL, then registers.
- On success stores:
- `seoworkerai_site_id`
- encrypted API token
- profile values from backend response

Key file:
- `includes/sync/class-site-registrar.php`

#### 2.2.2 Laravel side

`SiteController::register()` handles:
- create or update semantics
- duplicate domain + mismatched key conflict
- ownership proof verification (required unless valid existing key provided)
- site status + profile defaults
- immediate site user sync

Response includes:
- `site_id`
- `api_key`
- status
- `oauth_url`
- bootstrap trigger flags (`deferred_until_google_oauth`)

Key file:
- `app/Http/Controllers/API/SiteController.php`

### 2.3 Ownership proof model

Laravel issues ownership challenges by intent:
- `register`
- `deactivate`
- `reactivate`

WP exposes proof endpoint and stores challenge token temporarily.

Paths:
- Laravel: `/api/sites/ownership/challenge`, `/api/sites/{site_id}/ownership/challenge`
- WP proof endpoint: `/wp-json/seoworkerai/v1/ownership-proof?challenge_id=...`

Key files:
- `app/Http/Controllers/API/SiteController.php`
- `includes/rest/class-ownership-proof-endpoint.php`
- `includes/auth/class-ownership-proof-store.php`

### 2.4 Google OAuth flow

#### 2.4.1 Initiation

WordPress:
- calls Laravel `/api/oauth/google/init` with return URL and requested scopes
- stores local oauth state flags (`in_progress`)

Laravel:
- validates scopes (`search_console`, `analytics`)
- maps to Google scopes
- stores state in cache (10 min)
- returns Socialite OAuth URL

#### 2.4.2 Callback + activation

Laravel callback (`/api/oauth/google/callback`):
- validates state
- exchanges code via Socialite
- upserts user and OAuth token
- marks site `Active`
- dispatches `BootstrapSiteSeoAfterGoogleAuthJob`
- redirects back to WP callback URL with status query params

WordPress callback handler:
- parses callback query
- updates local OAuth options
- runs health check
- marks active/error accordingly

Key files:
- `app/Http/Controllers/API/OAuthController.php`
- `app/Jobs/Seo/BootstrapSiteSeoAfterGoogleAuthJob.php`
- `includes/auth/class-oauth-handler.php`

### 2.5 Post-OAuth bootstrap and initial audit

After successful OAuth:
1. Laravel bootstraps site tasks and schedules via `SiteSeoSetupService` and scheduler.
2. Dispatches initial indexed pages audit (`AuditSiteIndexedPagesJob`).
3. Recurring and registration category tasks become schedulable.

Key files:
- `app/Jobs/Seo/BootstrapSiteSeoAfterGoogleAuthJob.php`
- `app/Services/Seo/SiteSeoTaskScheduler.php`
- `app/Services/Seo/SeoTaskOrchestrator.php`

## 3. Event-Driven SEO Processing

### 3.1 Event capture in WordPress

Plugin collects events from WP hooks:
- `save_post_page`, `save_post_post`, `save_post_product`
- `publish_post`, `before_delete_post`
- `add_attachment`
- plugin activate/deactivate
- theme switch

Skips autosaves/revisions/unpublished posts.
Respects excluded pages list (`seoworkerai_excluded_change_audit_pages`) by id/slug/url matching.

Mapped payload includes:
- event type
- post id/type/url/title/content
- SEO metadata snapshot (title, description, canonical, schema, social tags)
- timestamps

Key files:
- `includes/events/class-event-collector.php`
- `includes/events/class-event-mapper.php`

### 3.2 Event outbox and dispatch

WordPress writes events to local outbox table, then queue worker flushes to Laravel:
- recurring queue hook: `seoworkerai_flush_events`
- outbox supports retry and status tracking

Laravel receives at:
- `POST /api/sites/{site_id}/events`

Supported event types include:
- page/post/product create/update/delete
- attachment uploaded
- plugin/theme changed

Key files:
- `includes/events/class-event-outbox.php`
- `includes/events/class-event-dispatcher.php`
- `app/Http/Controllers/API/SiteSeoEventController.php`

### 3.3 On-page issue detection and recommendation generation

Laravel orchestrator processes events and triggers:
- immediate recommendation detection
- task scheduling based on event type

`OnPageIssueDetector` currently creates recommendations/actions for cases such as:
- missing meta description
- missing schema JSON-LD
- missing social tags
- missing published/modified metadata
- heading structure problems
- missing image alt attributes
- transactional template pages routed to human review

It can auto-create actionable payloads (including generated descriptions, suggested alt text, schema, etc.).

Key files:
- `app/Services/Seo/SeoTaskOrchestrator.php`
- `app/Services/Seo/OnPageIssueDetector.php`

## 4. Task Orchestration and Execution Engine (Laravel)

### 4.1 Task model

Tasks are defined in `seo_execution_tasks` with per-site overrides in `site_seo_execution_tasks`.
Task categories:
- registration
- recurring
- event
- manual

Each task has ordered steps (`seo_execution_task_steps`) with types:
- `seo_task_call`
- `ai_task_call`
- `decision_gate`
- `action_dispatch`

### 4.2 Scheduler behavior

Scheduler responsibilities:
- initialize per-site task settings
- create registration schedules
- ensure recurring next-run schedules from cron expression
- dispatch due schedules
- reschedule recurring tasks after dispatch

Manual scheduling endpoint:
- `POST /api/seo/tasks/{task_id}/schedule`

Task config endpoint:
- `PATCH /api/seo/tasks/{task_id}`

Key file:
- `app/Services/Seo/SiteSeoTaskScheduler.php`

### 4.3 Runtime execution job

`RunSeoExecutionTaskJob` flow:
1. marks execution log running
2. resolves active ordered steps
3. builds execution context (site/task/date/input)
4. executes each step with conditional `run_if`
5. captures step result/failure
6. updates progress throughout
7. marks final status (completed/failed)

If missing site profile and task requires AI, it chains profile hydration before execution.

Key files:
- `app/Jobs/Seo/RunSeoExecutionTaskJob.php`
- `app/Services/Seo/SeoTaskOrchestrator.php`

### 4.4 Execution observability APIs

- tasks list: `GET /api/seo/tasks`
- scheduled tasks: `GET /api/seo/scheduled-tasks`
- execution logs: `GET /api/seo/execution-logs`

`execution_logs` include task metadata, status, progress, durations, result payloads, and linked scheduled tasks.

Key file:
- `app/Http/Controllers/API/SiteSeoExecutionLogController.php`

## 5. Action Lifecycle (Laravel -> WordPress -> Laravel)

### 5.1 Action creation and deduplication (Laravel)

`SeoActionDispatcher::createAction()` upserts action queue records by matching:
- site
- action type
- target type/id
- active statuses

This prevents duplicate pending/applied active actions and updates existing payload/priority when needed.

### 5.2 Provider dispatch

Laravel dispatch task `platform_dispatch_action` sends action payload to provider and tracks sync log:
- outbound sync logged in `seo_execution_sync_logs`
- action status transitions to `sent_to_provider` on success
- provider errors set `provider_error` and retry hints

Status updates accepted from WP:
- `provider_applied`
- `provider_rolled_back`
- `provider_error`
- `rejected`

Key file:
- `app/Services/Seo/SeoActionDispatcher.php`

### 5.3 WordPress pending-action polling

WordPress polls Laravel endpoint:
- `GET /api/sites/{site_id}/actions/pending`

Polling worker:
- stores new actions in local `seoworkerai_actions`
- enqueues execution jobs (`seoworkerai_execute_action`)
- supports review mode vs auto-apply mode

Key files:
- `includes/actions/class-action-poller.php`
- `includes/actions/class-action-receiver.php`
- `includes/queue/class-queue-manager.php`

### 5.4 WordPress action execution

`ActionExecutor` resolves handler by action type and performs:
1. lock acquisition (action + entity lock)
2. move to running
3. handler validation
4. handler execute
5. store snapshots/status
6. report status back to Laravel

Supported action families include (non-exhaustive):
- meta description add/update
- title updates
- canonical
- schema
- redirects
- broken link fixes
- internal links
- heading adjustments
- technical flags
- sitemap/robots
- indexing submit
- social tags
- post dates
- alt text
- human-action-required

Key file:
- `includes/actions/class-action-executor.php`

### 5.5 Failure and rollback behavior

If execution throws:
- executor attempts handler rollback
- sets final WP status (`rolled_back` or `failed`)
- reports status/error metadata to Laravel
- logs failure event

Manual rollback from WP admin uses `revertByLaravelId()` and, on success, sends `rolled_back` status upstream.

## 6. Admin UI Functional Areas (WordPress)

Main visible tabs in admin shell:
- Settings
- Change Center
- Action Items
- Content Briefs

Hidden (direct URL only) pages:
- Debug Logs (`page=seoworkerai-local-errors`)
- Schedules (`page=seoworkerai-schedules`)
- OAuth callback pages

Key file:
- `includes/admin/class-menu-registrar.php`

### 6.1 Settings

Capabilities:
- register/sync site
- start/reconnect/disconnect Google OAuth
- health check
- API token rotation
- SEO adapter selection
- change application mode
- debug toggle
- insecure SSL toggle (dev)
- Excluded Pages from Audits selector

### 6.2 Change Center

Purpose:
- view all actions and lifecycle progression
- multi-select filtering (status/action type/target type/page)
- search and pagination
- inline payload editing for editable action fields
- revert actions
- delete execution logs

It also correlates each action with change-log progression entries (`received`, `queued`, `applied`, `failed`, `edited`, `reverted`, etc.).

### 6.3 Action Items

Displays human-action-required items and linked action context. Supports status updates and quick navigation to edit target posts.

### 6.4 Debug Logs and Schedules (hidden pages)

Debug Logs:
- activity log viewer with severity/event filters and bulk deletion options.

Schedules:
- fetches backend tasks/scheduled tasks
- shows current scheduling status
- supports manual scheduling of tasks from WP UI.

### 6.5 Content Briefs

Syncs briefs from Laravel and allows linking WP posts/articles back to the brief record.

Related API endpoints:
- `GET /api/sites/{site_id}/content-briefs`
- `POST /api/sites/{site_id}/content-briefs/{content_brief_id}/link-article`

## 7. Queueing, Cron, and Background Jobs (WordPress)

Queue manager supports Action Scheduler if available, else WP-Cron fallback.

Recurring hooks:
- `seoworkerai_flush_events` (1 min)
- `seoworkerai_poll_actions` (5 min)
- `seoworkerai_sync_briefs` (10 min)
- `seoworkerai_sync_users` (hourly)
- `seoworkerai_cleanup` (daily)

Also handles:
- async action execution
- ack retry scheduling
- heartbeat update (`seoworkerai_last_cron_run`)
- data retention cleanup (old outbox/logs/actions/locks)

Key file:
- `includes/queue/class-queue-manager.php`

## 8. Data Persistence

### 8.1 WordPress plugin tables

Created/upgraded in `Schema::createOrUpgrade()`:
- `seoworkerai_actions`
- `seoworkerai_event_outbox`
- `seoworkerai_activity_logs`
- `seoworkerai_change_logs`
- `seoworkerai_admin_action_items`
- `seoworkerai_content_briefs`
- `seoworkerai_locks`
- `seoworkerai_redirects`

Each table captures a dedicated concern:
- action state + snapshots
- event delivery queue
- operational logs
- audit progression timeline
- human tasks
- brief cache and linking
- distributed lock emulation
- redirect runtime data

Key file:
- `includes/storage/class-schema.php`

### 8.2 Laravel persistence (functional groups)

Core entities involved in runtime:
- `sites`, `site_users`, `oauth_tokens`
- `seo_event_triggers`
- `seo_execution_tasks`, `seo_execution_task_steps`
- `site_seo_execution_tasks`, `site_seo_scheduled_tasks`
- `seo_execution_logs`, `job_logs`
- `seo_action_queues`, `seo_execution_sync_logs`
- `content_briefs` and related keyword/thread/insight tables

## 9. Security and Access Control

- Laravel authenticated site APIs are protected by `verify.site.token` middleware.
- Middleware resolves authenticated `site` from token and injects into request.
- Site-scoped controllers enforce `site_id` match checks.
- WP admin actions enforce capabilities (`manage_options`, `edit_posts`) and nonce checks.
- Ownership proof gate protects critical register/deactivate/reactivate transitions.

Key file:
- `app/Http/Middleware/VerifySiteToken.php`

## 10. Error Handling, Retries, and Resilience

### 10.1 WordPress side

- API calls generally wrapped with retry policy (except explicit fast single-attempt admin paths).
- Outbox + ack retry mechanics reduce data loss during transient outages.
- Locking avoids duplicate concurrent action execution.
- Local activity/change logs preserve diagnostics and progression.

### 10.2 Laravel side

- Dispatch and status updates tracked with sync logs.
- Provider errors moved to explicit queue statuses.
- Scheduled task dispatch handles missing resources and writes failure reasons.
- Execution job captures step-level failures and supports `continue_on_failure` paths.

## 11. End-to-End Operational Narrative

From a clean install to steady state:
1. WP plugin boots, creates schema, registers hooks.
2. Admin syncs site registration (ownership proof verified).
3. API token and site id are stored locally.
4. Admin connects Google OAuth.
5. Laravel marks site active and bootstraps SEO tasking.
6. Initial audits/schedules are queued.
7. WP content/system events stream to Laravel via outbox.
8. Laravel detects issues, creates recommendations, schedules tasks, and creates action queue entries.
9. WP polls pending actions, executes via handlers, and reports statuses back.
10. Change Center and Action Items expose outcomes, manual edits, and rollbacks.
11. Recurring schedules keep continuous optimization running.
12. Logs/sync/cleanup loops maintain observability and hygiene.

## 12. API Surface Summary

Public:
- `POST /api/sites/register`
- `POST /api/sites/{site_id}/register`
- `POST /api/sites/ownership/challenge`
- `GET /api/oauth/google/callback`

Site-authenticated (token required):
- OAuth init/revoke
- site verify/health/profile/token/deactivate/reactivate/ownership challenge
- event ingestion
- action pending/dispatch/status
- tasks list/update/schedule
- scheduled task list
- execution log list
- content briefs list/link-article

Primary route map:
- `routes/api.php`

## 13. Notes for Future Maintainers

- WP admin shell intentionally hides Debug Logs and Schedules from visible tabs; they remain direct-link pages.
- Change Center and Action Items rely on local WP DB mirrors and may diverge from Laravel if sync retries are pending; check both sides when diagnosing.
- When debugging action failures, inspect:
- WP: `seoworkerai_activity_logs`, `seoworkerai_change_logs`, `seoworkerai_actions`
- Laravel: `seo_action_queues`, `seo_execution_sync_logs`, `seo_execution_logs`

## 14. Seeded Detection and Decision Logic (If This, Then That)

This section is derived from:
- `database/seeders/SeoExecutionTaskSeeder.php`
- `database/seeders/AiSchemas.php`
- `database/seeders/AiPrompts.php`
- `app/Jobs/Seo/RunSeoExecutionTaskJob.php`

### 14.1 Decision gates and how they work

Decision gates are explicit `DecisionGate` task steps with `decision_config`:
- `path`: where to read value from execution context
- `operator`: comparison (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains`, `in`, `exists`, `empty`, `not-empty`, `true`, `false`)
- `value`: expected value (for operators that need it)
- `store_as`: writes boolean result into `flags.<name>`
- optional `store_path`: writes boolean result into an arbitrary context path

Execution behavior:
1. Step resolves templates like `{{steps.x.result...}}`.
2. Job evaluates condition.
3. Result is stored (typically in flags).
4. Later steps can use `run_if` to execute only when a flag condition passes.

This is how the system does deterministic branching instead of free-form AI flow control.

### 14.2 Seeded gate inventory (current)

- `ownership_detected` -> sets `flags.gsc_verified` when GSC permission level is present.
- `has_prioritized_issues` -> sets `flags.has_onpage_actions` when prioritized on-page issue list is non-empty.
- `significant_rank_change` -> sets `flags.rank_change_detected` when rank-change analysis has non-empty result payload.
- `has_daily_actions` -> sets `flags.daily_actions_ready` when GA-driven structured actions array is non-empty.
- `schema_requires_fix` -> sets `flags.schema_fix_needed` when schema validation returns fixable issues.
- `meta_suggestion_ready` -> sets `flags.meta_ready` when title/meta suggestions are available.
- `redirect_suggestion_ready` -> sets `flags.redirect_ready` when redirect map output is available.
- `alt_generated` -> sets `flags.alt_ready` when ALT text is produced.
- `manual_redirect_ready` -> sets `flags.manual_redirect_ready` when manual redirect map output exists.

### 14.3 If-Then action branching implemented in seeders

Event-driven deterministic branches:
- If `flags.meta_ready = true`, then queue `add-meta-description` action for that post.
- If `flags.redirect_ready = true`, then queue `add-redirect` action for deleted page URL.
- If `flags.alt_ready = true`, then queue `add-alt-text` action for uploaded attachment.

These are implemented as `ActionDispatch` steps with `run_if` conditions.

### 14.4 What kinds of issues are detected

#### 14.4.1 Real-time/event detectors (WP event -> Laravel orchestration)

From on-page/event analysis services and seeded event tasks, the system can detect:
- Missing meta descriptions.
- Missing/invalid schema markup patterns.
- Missing social tags (Open Graph/Twitter fields).
- Missing publish/modified date metadata.
- Heading-structure problems (for example, first heading not H1).
- Missing image ALT attributes.
- Broken/deleted URL follow-up needs (redirect planning and impact analysis).
- Internal linking opportunities from page context.
- Content compliance gaps (word count, heading structure, keyword density, supporting keyword coverage, readability).
- Ranking volatility/significant movement signals.
- Cannibalization patterns (same-intent URL overlap).
- Crawl/index coverage issue clusters (404/soft404/noindex/robots/redirect errors).
- Competitive content gaps and keyword opportunities.
- Technology stack change impact cues.

#### 14.4.2 Structured AI output constraints

Seeded schemas enforce strict output shape so downstream automation can trust data contracts:
- Title/meta suggestions constrained by count/length rules.
- Redirect map requires source/target URLs, type, relevance score, low-confidence flag.
- Structured daily actions constrained to known action enums.
- Compliance checks return boolean checks + scored issues + actionable recommendations.
- Cannibalization output requires recommended action type (`canonical`, `consolidate`, `differentiate`).

### 14.5 Seeded recurring/registration/manual capabilities

The task catalog spans:
- Registration baseline: ownership verification, site profile derivation, initial GSC/data pulls, crawl/error analysis, initial plan generation.
- Recurring monitoring: daily/weekly/monthly loops for rankings, coverage, crawl, cannibalization, content gaps, schema, backlinks, technical audits, plan regeneration.
- Event tasks: page update/deletion, media upload, schema/meta/compliance/internal links/indexing, tech stack updates.
- Manual tasks: on-demand sync/research/briefs/schema/linking/redirect maps/technical audits.

### 14.6 Automated action scope: what plugin can execute directly

At runtime, WordPress has handlers for:
- `add-meta-description`, `update-meta-description`
- `update-title`
- `add-canonical`
- `add-schema` / `add-schema-markup`
- `add-redirect`
- `fix-broken-link`
- `add-internal-link`
- `adjust-headings`
- `technical-seo-flags`
- `sitemap-update`
- `robots-directives`
- `submit-for-indexing`
- `set-social-tags`
- `set-post-dates`
- `add-alt-text`
- `monitor-only`
- `human-action-required` (creates tracked admin item instead of direct content mutation)

### 14.7 What still needs an SEO specialist (critical responsibilities)

The platform automates detection + queueing + many on-site edits, but specialist ownership is still needed for:
- Strategy and prioritization:
  - Business goals, market positioning, page-level intent strategy, and tradeoff decisions.
- Quality control of AI suggestions:
  - Validate brand tone, legal/compliance constraints, and factual correctness.
- Cannibalization resolution choice:
  - Deciding between canonicalization vs consolidation vs differentiation requires business/context judgment.
- Redirect governance:
  - Approving low-confidence redirect targets and migration-level redirect architecture.
- Content depth and E-E-A-T:
  - Author expertise, citation quality, and editorial quality standards.
- Technical implementation beyond plugin scope:
  - Theme/template architecture, Core Web Vitals engineering, JS rendering constraints, faceted navigation controls.
- External SEO work:
  - Link acquisition/digital PR, local SEO operations, SERP feature strategy, competitor-led campaign planning.
- Risk management:
  - Reviewing high-impact queued changes before rollout on revenue-critical pages.

### 14.8 Practical operating model

Recommended division of labor:
1. Let platform run continuous detection, scheduling, and low-risk deterministic fixes.
2. Route medium/high-risk or low-confidence recommendations to human review.
3. SEO specialist runs weekly review of:
   - Open `human-action-required` items
   - Failed/rolled-back actions
   - Cannibalization/redirect/compliance recommendations
4. Specialist owns monthly strategy updates; platform executes the operational loop.
