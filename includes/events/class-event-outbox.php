<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Events;

use SEOAutomation\Connector\Utils\JsonHelper;

final class EventOutbox
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'seoauto_outbox';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function queue(array $payload): bool
    {
        global $wpdb;

        $eventType = sanitize_text_field((string) ($payload['event_type'] ?? ''));
        $postId = (int) ($payload['post_id'] ?? 0);
        $eventTime = (string) ($payload['event_time'] ?? gmdate('c'));
        $eventKey = hash('sha256', $eventType . ':' . $postId . ':' . $eventTime);

        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'event_key' => $eventKey,
                'event_type' => $eventType,
                'payload' => JsonHelper::encode($payload),
                'status' => 'queued',
                'attempts' => 0,
                'next_attempt_at' => current_time('mysql'),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $inserted !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDispatchable(int $limit = 25): array
    {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = 'queued' AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
                current_time('mysql'),
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function markSent(int $id): void
    {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'status' => 'sent',
                'sent_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function markRetry(int $id, int $attempts, int $delaySeconds, string $error, bool $finalFail = false): void
    {
        global $wpdb;

        $status = $finalFail ? 'failed' : 'queued';
        $nextAttempt = gmdate('Y-m-d H:i:s', time() + $delaySeconds);

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'status' => $status,
                'attempts' => $attempts,
                'next_attempt_at' => $nextAttempt,
                'last_error' => $error,
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function cleanupOlderThanDays(int $days = 30): void
    {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
                current_time('mysql'),
                $days
            )
        );
    }
}
