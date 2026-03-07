<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector;

use SEOWorkerAI\Connector\Queue\QueueManager;
use SEOWorkerAI\Connector\Storage\Schema;

final class Activator
{
    public static function activate(): void
    {
        Schema::createOrUpgrade();
        self::setDefaultOptions();
        self::scheduleAutoRegistration();
        QueueManager::scheduleRecurringJobs();

        flush_rewrite_rules();
    }

    private static function setDefaultOptions(): void
    {
        add_option('seoworkerai_base_url', rtrim((string) SEOWORKERAI_LARAVEL_BASE_URL, '/'), '', 'no');
        add_option('seoworkerai_site_id', 0, '', 'no');
        add_option('seoworkerai_site_token', '', '', 'no');
        add_option('seoworkerai_debug_enabled', false, '', 'no');
        add_option('seoworkerai_allow_insecure_ssl', false, '', 'no');
        add_option('seoworkerai_primary_seo_adapter', 'auto', '', 'no');
        add_option('seoworkerai_change_application_mode', 'dangerous_auto_apply', '', 'no');
        add_option('seoworkerai_last_user_sync', 0, '', 'no');
        add_option('seoworkerai_last_brief_sync', 0, '', 'no');
        add_option('seoworkerai_oauth_status', 'pending', '', 'no');
        add_option('seoworkerai_oauth_scopes', [], '', 'no');
        add_option('seoworkerai_oauth_provider', '', '', 'no');
        add_option('seoworkerai_oauth_connected_at', 0, '', 'no');
        add_option('seoworkerai_oauth_last_error', '', '', 'no');
        add_option('seoworkerai_provider_connection_alerts', [], '', 'no');
        add_option('seoworkerai_owner_platform_user_id', '1', '', 'no');
        add_option('seoworkerai_site_profile_description', '', '', 'no');
        add_option('seoworkerai_site_profile_taste', '', '', 'no');
        add_option('seoworkerai_site_locations', [], '', 'no');
        add_option('seoworkerai_site_location_code', 2840, '', 'no');
        add_option('seoworkerai_site_location_name', 'United States', '', 'no');
        add_option('seoworkerai_site_seo_settings', [], '', 'no');
        add_option('seoworkerai_billing', [], '', 'no');
        add_option('seoworkerai_excluded_change_audit_pages', '', '', 'no');
        add_option(
            'seoworkerai_features',
            [
                'auto_apply_actions' => true,
                'send_events' => true,
                'sync_briefs' => true,
            ],
            '',
            'no'
        );
        add_option('seoworkerai_adapter_priority', ['yoast', 'rankmath', 'aioseo'], '', 'no');
        add_option('seoworkerai_mirror_post_title', false, '', 'no');
        add_option('seoworkerai_canonical_same_host', true, '', 'no');
        add_option('seoworkerai_api_blocked', false, '', 'no');
        add_option('seoworkerai_api_last_error', '', '', 'no');
        add_option('seoworkerai_api_last_error_at', 0, '', 'no');
        add_option('seoworkerai_ownership_challenges', [], '', 'no');
        add_option('seoworkerai_auto_register_pending', true, '', 'no');
    }

    private static function scheduleAutoRegistration(): void
    {
        if (!wp_next_scheduled('seoworkerai_auto_register_site')) {
            wp_schedule_single_event(time() + 15, 'seoworkerai_auto_register_site');
        }
    }
}
