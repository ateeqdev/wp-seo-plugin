=== SEOWorkerAI.com ===
Contributors: hardtoskip
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEOWorkerAI.com connects a WordPress site to the SEOWorkerAI SaaS for site registration, content briefs, SEO change execution, and reporting.

== Description ==

SEOWorkerAI.com syncs a WordPress site with the SEOWorkerAI SaaS so site owners can:

- register and verify a site
- sync site profile, locations, and SEO settings
- receive content briefs generated from SEO data and Reddit-backed research
- receive approved SEO changes from the SaaS and apply or revert them in WordPress
- sync action status, health signals, and activity logs back to the SaaS

== External services ==

This plugin connects to the SEOWorkerAI SaaS and related third-party integrations configured there.

1. SEOWorkerAI SaaS
- What it is used for: site registration, ownership verification, billing status, Google OAuth initialization, content brief sync, action sync, and SEO orchestration.
- Data sent and when:
  - during registration or sync: site URL, WordPress version, timezone, admin email, synced user list, site description, taste, locations, and site SEO settings
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
5. Complete payment if required.
6. Connect Google services when prompted.

== Frequently Asked Questions ==

= Does the plugin work without the SEOWorkerAI SaaS? =

No. The plugin requires a reachable SEOWorkerAI backend.

= Does the plugin send data off-site? =

Yes. Site profile, settings, actions, and sync metadata are sent to the SEOWorkerAI SaaS as described above.

== Changelog ==

= 2.0.0 =
- Initial branded release for SEOWorkerAI.com.
