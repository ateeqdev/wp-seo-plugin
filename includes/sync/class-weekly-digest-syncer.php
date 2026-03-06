<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Sync;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Utils\JsonHelper;
use SEOAutomation\Connector\Utils\Logger;

final class WeeklyDigestSyncer
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
        if (!(bool) ($features['sync_weekly_digest'] ?? true)) {
            return;
        }

        $siteId = (int) get_option('seoauto_site_id', 0);
        if ($siteId <= 0) {
            return;
        }

        try {
            $response = $this->client->getWeeklyDigest($siteId, true);
            $digest = isset($response['digest']) && is_array($response['digest']) ? $response['digest'] : null;
            if (!is_array($digest) || empty($digest['id'])) {
                update_option('seoauto_last_digest_sync', time(), false);
                return;
            }

            $this->upsertDigest($siteId, $digest);
            update_option('seoauto_last_digest_sync', time(), false);
        } catch (\Throwable $exception) {
            $this->logger->warning('weekly_digest_sync_failed', [
                'entity_type' => 'site',
                'entity_id' => (string) $siteId,
                'error' => $exception->getMessage(),
            ], 'outbound');
        }
    }

    /**
     * @param array<string, mixed> $digest
     */
    private function upsertDigest(int $siteId, array $digest): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'seoauto_weekly_digests';
        $laravelDigestId = (int) ($digest['id'] ?? 0);
        if ($laravelDigestId <= 0) {
            return;
        }

        $row = [
            'laravel_digest_id' => $laravelDigestId,
            'site_id' => $siteId,
            'digest_payload' => JsonHelper::encode($digest),
            'generated_at' => $this->toMysqlDateTime($digest['generated_at'] ?? ''),
            'period_start' => $this->toMysqlDate($digest['period_start'] ?? ''),
            'period_end' => $this->toMysqlDate($digest['period_end'] ?? ''),
            'synced_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE laravel_digest_id = %d",
                $laravelDigestId
            )
        );

        if ($exists !== null) {
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $table,
                $row,
                ['laravel_digest_id' => $laravelDigestId],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            return;
        }

        $row['created_at'] = current_time('mysql');
        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            $row,
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * @param mixed $value
     */
    private function toMysqlDateTime($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param mixed $value
     */
    private function toMysqlDate($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d', $timestamp);
    }
}
