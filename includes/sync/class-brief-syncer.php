<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Sync;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Utils\JsonHelper;
use SEOAutomation\Connector\Utils\Logger;

final class BriefSyncer
{
    private LaravelClient $client;

    private Logger $logger;

    public function __construct(LaravelClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function sync(): void
    {
        $features = (array) get_option('seoauto_features', []);
        if (!(bool) ($features['sync_briefs'] ?? true)) {
            return;
        }

        $siteId = (int) get_option('seoauto_site_id', 0);
        if ($siteId <= 0) {
            return;
        }

        try {
            $response = $this->client->listContentBriefs($siteId, ['limit' => 50]);
            $briefs = isset($response['content_briefs']) && is_array($response['content_briefs'])
                ? $response['content_briefs']
                : [];

            global $wpdb;
            $table = $wpdb->prefix . 'seoauto_content_briefs';

            foreach ($briefs as $brief) {
                if (!is_array($brief) || empty($brief['id'])) {
                    continue;
                }

                $row = [
                    'laravel_content_brief_id' => (int) $brief['id'],
                    'payload' => JsonHelper::encode($brief),
                    'article_status' => sanitize_text_field((string) ($brief['article_status'] ?? 'unlinked')),
                    'synced_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ];

                $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE laravel_content_brief_id = %d",
                        (int) $brief['id']
                    )
                );

                if ($exists !== null) {
                    $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $table,
                        $row,
                        ['laravel_content_brief_id' => (int) $brief['id']],
                        ['%d', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                } else {
                    $row['assignment_status'] = 'unassigned';
                    $row['created_at'] = current_time('mysql');
                    $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $table,
                        $row,
                        ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
                    );
                }
            }

            update_option('seoauto_last_brief_sync', time(), false);
        } catch (\Throwable $exception) {
            $this->logger->warning('brief_sync_failed', [
                'entity_type' => 'site',
                'entity_id' => (string) $siteId,
                'error' => $exception->getMessage(),
            ], 'outbound');
        }
    }
}
