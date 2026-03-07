<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$options = [
    'seoworkerai_base_url',
    'seoworkerai_site_id',
    'seoworkerai_site_token',
    'seoworkerai_debug_enabled',
    'seoworkerai_primary_seo_adapter',
    'seoworkerai_change_application_mode',
    'seoworkerai_last_user_sync',
    'seoworkerai_last_brief_sync',
    'seoworkerai_oauth_status',
    'seoworkerai_oauth_scopes',
    'seoworkerai_oauth_provider',
    'seoworkerai_oauth_connected_at',
    'seoworkerai_oauth_last_error',
    'seoworkerai_provider_connection_alerts',
    'seoworkerai_owner_platform_user_id',
    'seoworkerai_site_profile_description',
    'seoworkerai_site_profile_taste',
    'seoworkerai_site_locations',
    'seoworkerai_site_location_code',
    'seoworkerai_site_location_name',
    'seoworkerai_site_seo_settings',
    'seoworkerai_billing',
    'seoworkerai_features',
    'seoworkerai_db_version',
    'seoworkerai_adapter_priority',
    'seoworkerai_mirror_post_title',
    'seoworkerai_canonical_same_host',
    'seoworkerai_api_blocked',
    'seoworkerai_api_last_error',
    'seoworkerai_api_last_error_at',
    'seoworkerai_last_cron_run',
    'seoworkerai_last_log_sync',
    'seoworkerai_robots_directives',
];

foreach ($options as $option) {
    delete_option($option);
}

$tables = [
    $wpdb->prefix . 'seoworkerai_actions',
    $wpdb->prefix . 'seoworkerai_event_outbox',
    $wpdb->prefix . 'seoworkerai_activity_logs',
    $wpdb->prefix . 'seoworkerai_change_logs',
    $wpdb->prefix . 'seoworkerai_admin_action_items',
    $wpdb->prefix . 'seoworkerai_content_briefs',
    $wpdb->prefix . 'seoworkerai_locks',
    $wpdb->prefix . 'seoworkerai_redirects',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
