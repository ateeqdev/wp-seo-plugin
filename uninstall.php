<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$options = [
    'seoauto_base_url',
    'seoauto_site_id',
    'seoauto_site_token',
    'seoauto_debug_enabled',
    'seoauto_primary_seo_adapter',
    'seoauto_change_application_mode',
    'seoauto_last_user_sync',
    'seoauto_last_brief_sync',
    'seoauto_oauth_status',
    'seoauto_oauth_scopes',
    'seoauto_oauth_provider',
    'seoauto_oauth_connected_at',
    'seoauto_oauth_last_error',
    'seoauto_provider_connection_alerts',
    'seoauto_owner_platform_user_id',
    'seoauto_site_profile_description',
    'seoauto_site_profile_taste',
    'seoauto_site_locations',
    'seoauto_site_location_code',
    'seoauto_site_location_name',
    'seoauto_site_seo_settings',
    'seoauto_billing',
    'seoauto_features',
    'seoauto_db_version',
    'seoauto_adapter_priority',
    'seoauto_mirror_post_title',
    'seoauto_canonical_same_host',
    'seoauto_api_blocked',
    'seoauto_api_last_error',
    'seoauto_api_last_error_at',
    'seoauto_last_cron_run',
    'seoauto_last_log_sync',
    'seoauto_robots_directives',
];

foreach ($options as $option) {
    delete_option($option);
}

$tables = [
    $wpdb->prefix . 'seoauto_actions',
    $wpdb->prefix . 'seoauto_event_outbox',
    $wpdb->prefix . 'seoauto_activity_logs',
    $wpdb->prefix . 'seoauto_change_logs',
    $wpdb->prefix . 'seoauto_admin_action_items',
    $wpdb->prefix . 'seoauto_content_briefs',
    $wpdb->prefix . 'seoauto_locks',
    $wpdb->prefix . 'seoauto_redirects',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
