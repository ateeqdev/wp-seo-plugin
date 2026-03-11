=== SEOWorkerAI.com ===
Contributors: hardtoskip
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEOWorkerAI.com runs a one-time site-wide SEO audit on install, applies fixes based on your settings, and unlocks ongoing recommendations once you activate a plan.

== Description ==

SEOWorkerAI.com helps site owners:

- run a free, one-time site-wide audit right after install
- fix technical SEO gaps automatically (or review each change first)
- connect Google Search Console and Google Analytics for future insights
- unlock ongoing recommendations, daily audits, and content briefs after payment

== External services ==

This plugin connects to the SEOWorkerAI SaaS and related third-party integrations configured there.

1. SEOWorkerAI SaaS
- What it is used for: site registration, ownership verification, billing status, the one-time initial audit, Google OAuth initialization, action sync, and SEO orchestration.
- Data sent and when:
  - during registration or sync: site URL, WordPress version, timezone, admin email, synced user list, site description, taste, locations, and site SEO settings so the SaaS can create or update the owning company and its memberships
  - during health, action, and event sync: site identifiers, action payloads, execution/revert status, and operational logs
  - during content brief sync: site identifier and authorization token
- Terms of Service: https://seoworkerai.com/terms
- Privacy Policy: https://seoworkerai.com/privacy

2. Google OAuth via SEOWorkerAI SaaS
- What it is used for: Google Search Console and Google Analytics access for SEO analysis in the SaaS.
- Data sent and when: OAuth scopes and the return URL are sent when an admin starts Google connection from the plugin settings screen.
- Terms of Service: https://policies.google.com/terms
- Privacy Policy: https://policies.google.com/privacy

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Open the SEOWorkerAI settings page in wp-admin.
4. Register the site.
5. Your initial audit runs automatically after registration.
6. Connect Google services anytime.
7. Activate payment to unlock ongoing recommendations and monitoring.

== Frequently Asked Questions ==

= Does the plugin work without the SEOWorkerAI SaaS? =

No. The plugin requires a reachable SEOWorkerAI backend to run the initial audit and sync changes.

= Does the plugin send data off-site? =

Yes. Site profile, settings, actions, and sync metadata are sent to the SEOWorkerAI SaaS as described above.

== Changelog ==

= 2.0.0 =
- Initial branded release for SEOWorkerAI.com.
