<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Storage;

use SEOWorkerAI\Connector\Utils\JsonHelper;

final class Schema
{
    public static function createOrUpgrade(): void
    {
        $current = (string) get_option('seoworkerai_db_version', '0.0.0');

        if (version_compare($current, SEOWORKERAI_DB_VERSION, '>=')) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $actions = $wpdb->prefix . 'seoworkerai_actions';
        $outbox = $wpdb->prefix . 'seoworkerai_outbox';
        $activityLogs = $wpdb->prefix . 'seoworkerai_logs';
        $changeLogs = $wpdb->prefix . 'seoworkerai_changes';
        $actionItems = $wpdb->prefix . 'seoworkerai_action_items';
        $briefs = $wpdb->prefix . 'seoworkerai_briefs';
        $locks = $wpdb->prefix . 'seoworkerai_locks';
        $redirects = $wpdb->prefix . 'seoworkerai_redirects';

        $sql = [];

        $sql[] = "CREATE TABLE {$actions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            laravel_action_id bigint(20) unsigned NOT NULL,
            action_type varchar(64) NOT NULL,
            target_type varchar(32) NOT NULL,
            target_id varchar(255) NOT NULL,
            target_url text DEFAULT NULL,
            action_payload longtext NOT NULL,
            auto_apply tinyint(1) unsigned NOT NULL DEFAULT 0,
            payload_checksum char(64) NOT NULL,
            status enum('received','queued','running','applied','failed','rejected','ack_pending','ack_failed','rolled_back') NOT NULL DEFAULT 'received',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            last_error text DEFAULT NULL,
            before_snapshot longtext DEFAULT NULL,
            after_snapshot longtext DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            received_at datetime NOT NULL,
            processed_at datetime DEFAULT NULL,
            acknowledged_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY laravel_action_id (laravel_action_id),
            KEY status_idx (status, received_at),
            KEY auto_apply_idx (auto_apply, status),
            KEY action_type_idx (action_type),
            KEY target_idx (target_type, target_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$outbox} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_key char(64) NOT NULL,
            event_type varchar(64) NOT NULL,
            payload longtext NOT NULL,
            status enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NOT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY status_next_attempt_idx (status, next_attempt_at),
            KEY event_type_idx (event_type),
            KEY created_at_idx (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$activityLogs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            correlation_id char(36) NOT NULL,
            source enum('inbound','outbound','executor','admin') NOT NULL,
            severity enum('debug','info','warning','error') NOT NULL DEFAULT 'info',
            event_name varchar(128) NOT NULL,
            entity_type varchar(32) DEFAULT NULL,
            entity_id varchar(255) DEFAULT NULL,
            request_payload longtext DEFAULT NULL,
            response_payload longtext DEFAULT NULL,
            before_value longtext DEFAULT NULL,
            after_value longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_severity_idx (event_name, severity),
            KEY created_at_idx (created_at),
            KEY entity_idx (entity_type, entity_id),
            KEY correlation_idx (correlation_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$changeLogs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_id bigint(20) unsigned DEFAULT NULL,
            laravel_action_id bigint(20) unsigned DEFAULT NULL,
            event_type enum('received','queued','applied','failed','rejected','edited','reverted','human_action_created') NOT NULL,
            status varchar(32) NOT NULL,
            actor_user_id bigint(20) unsigned DEFAULT NULL,
            note text DEFAULT NULL,
            before_snapshot longtext DEFAULT NULL,
            after_snapshot longtext DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY action_idx (action_id, created_at),
            KEY laravel_action_idx (laravel_action_id),
            KEY event_type_idx (event_type),
            KEY created_at_idx (created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$actionItems} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_id bigint(20) unsigned DEFAULT NULL,
            laravel_action_id bigint(20) unsigned DEFAULT NULL,
            site_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) NOT NULL,
            details text DEFAULT NULL,
            recommended_value varchar(255) DEFAULT NULL,
            category varchar(64) NOT NULL DEFAULT 'general',
            status enum('open','in_progress','resolved') NOT NULL DEFAULT 'open',
            resolved_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_idx (status, updated_at),
            KEY action_idx (action_id),
            KEY laravel_action_idx (laravel_action_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$briefs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            laravel_content_brief_id bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            brief_title varchar(255) DEFAULT NULL,
            focus_keyword varchar(255) DEFAULT NULL,
            keyword_type varchar(32) DEFAULT NULL,
            search_intent varchar(64) DEFAULT NULL,
            search_volume int(11) DEFAULT NULL,
            keyword_difficulty smallint(5) unsigned DEFAULT NULL,
            topic_priority_score int(11) DEFAULT NULL,
            strategy_template_name varchar(255) DEFAULT NULL,
            primary_subreddit varchar(120) DEFAULT NULL,
            article_status varchar(32) NOT NULL,
            assigned_wp_user_id bigint(20) unsigned DEFAULT NULL,
            assignment_status enum('unassigned','assigned','started','completed') NOT NULL DEFAULT 'unassigned',
            linked_wp_post_id bigint(20) unsigned DEFAULT NULL,
            linked_wp_post_title varchar(255) DEFAULT NULL,
            linked_wp_post_type varchar(32) DEFAULT NULL,
            linked_wp_post_url text DEFAULT NULL,
            synced_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY laravel_brief_id (laravel_content_brief_id),
            KEY article_status_idx (article_status),
            KEY assignment_status_idx (assignment_status),
            KEY keyword_type_idx (keyword_type),
            KEY search_intent_idx (search_intent),
            KEY linked_post_idx (linked_wp_post_id),
            KEY assigned_user_idx (assigned_wp_user_id),
            KEY synced_at_idx (synced_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$locks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lock_key varchar(191) NOT NULL,
            owner char(36) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lock_key (lock_key),
            KEY expires_at (expires_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$redirects} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_url varchar(512) NOT NULL,
            target_url varchar(512) NOT NULL,
            redirect_type smallint(3) unsigned NOT NULL DEFAULT 301,
            hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
            last_hit datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_url (source_url(191)),
            KEY target_url (target_url(191))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        self::backfillBriefMetadata();

        update_option('seoworkerai_db_version', SEOWORKERAI_DB_VERSION, false);
    }

    private static function backfillBriefMetadata(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'seoworkerai_briefs';
        $rows = $wpdb->get_results("SELECT id, payload FROM {$table} WHERE brief_title IS NULL OR focus_keyword IS NULL OR keyword_type IS NULL LIMIT 500", ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        if (!is_array($rows) || $rows === []) {
            return;
        }

        foreach ($rows as $row) {
            $payload = JsonHelper::decodeArray((string) ($row['payload'] ?? ''));
            if (!is_array($payload)) {
                continue;
            }

            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $table,
                [
                    'brief_title' => sanitize_text_field((string) ($payload['brief_title'] ?? '')),
                    'focus_keyword' => sanitize_text_field((string) ($payload['focus_keyword'] ?? '')),
                    'keyword_type' => sanitize_text_field((string) ($payload['keyword_type'] ?? '')),
                    'search_intent' => sanitize_text_field((string) ($payload['search_intent'] ?? '')),
                    'search_volume' => isset($payload['search_volume']) ? (int) $payload['search_volume'] : null,
                    'keyword_difficulty' => isset($payload['keyword_difficulty']) ? (int) $payload['keyword_difficulty'] : null,
                    'topic_priority_score' => isset($payload['topic_priority_score']) ? (int) $payload['topic_priority_score'] : null,
                    'strategy_template_name' => sanitize_text_field((string) ($payload['strategy_template_name'] ?? '')),
                    'primary_subreddit' => sanitize_text_field((string) ($payload['primary_subreddit'] ?? '')),
                    'linked_wp_post_title' => sanitize_text_field((string) ($payload['linked_wp_post_title'] ?? '')),
                    'linked_wp_post_type' => sanitize_text_field((string) ($payload['linked_wp_post_type'] ?? '')),
                ],
                ['id' => (int) $row['id']],
                ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        }
    }
}
