<?php

declare(strict_types=1);

namespace SEOAutomation\Connector;

use SEOAutomation\Connector\Queue\QueueManager;
use SEOAutomation\Connector\Storage\Schema;

final class Activator
{
    public static function activate(): void
    {
        Schema::createOrUpgrade();
        self::setDefaultOptions();
        QueueManager::scheduleRecurringJobs();

        flush_rewrite_rules();
    }

    private static function setDefaultOptions(): void
    {
        add_option('seoauto_base_url', rtrim((string) SEOAUTO_LARAVEL_BASE_URL, '/'), '', 'no');
        add_option('seoauto_site_id', 0, '', 'no');
        add_option('seoauto_site_token', '', '', 'no');
        add_option('seoauto_debug_enabled', false, '', 'no');
        add_option('seoauto_allow_insecure_ssl', false, '', 'no');
        add_option('seoauto_primary_seo_adapter', 'auto', '', 'no');
        add_option('seoauto_change_application_mode', 'dangerous_auto_apply', '', 'no');
        add_option('seoauto_last_user_sync', 0, '', 'no');
        add_option('seoauto_last_brief_sync', 0, '', 'no');
        add_option('seoauto_oauth_status', 'pending', '', 'no');
        add_option('seoauto_oauth_scopes', [], '', 'no');
        add_option('seoauto_oauth_provider', '', '', 'no');
        add_option('seoauto_oauth_connected_at', 0, '', 'no');
        add_option('seoauto_oauth_last_error', '', '', 'no');
        add_option('seoauto_provider_connection_alerts', [], '', 'no');
        add_option('seoauto_owner_platform_user_id', '1', '', 'no');
        add_option('seoauto_site_profile_description', '', '', 'no');
        add_option('seoauto_site_profile_taste', '', '', 'no');
        add_option('seoauto_excluded_change_audit_pages', '', '', 'no');
        add_option(
            'seoauto_features',
            [
                'auto_apply_actions' => true,
                'send_events' => true,
                'sync_briefs' => true,
            ],
            '',
            'no'
        );
        add_option('seoauto_adapter_priority', ['yoast', 'rankmath', 'aioseo'], '', 'no');
        add_option('seoauto_mirror_post_title', false, '', 'no');
        add_option('seoauto_canonical_same_host', true, '', 'no');
        add_option('seoauto_api_blocked', false, '', 'no');
        add_option('seoauto_api_last_error', '', '', 'no');
        add_option('seoauto_api_last_error_at', 0, '', 'no');
    }
}
