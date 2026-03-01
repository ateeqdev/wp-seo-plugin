<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions;

use SEOAutomation\Connector\Utils\JsonHelper;

final class ActionRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'seoauto_actions';
    }

    public function existsByLaravelId(int $laravelActionId): bool
    {
        global $wpdb;

        $id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare("SELECT id FROM {$this->table} WHERE laravel_action_id = %d", $laravelActionId)
        );

        return $id !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): bool
    {
        global $wpdb;

        $payload = isset($data['action_payload']) && is_array($data['action_payload'])
            ? $data['action_payload']
            : [];

        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'laravel_action_id' => (int) ($data['laravel_action_id'] ?? 0),
                'action_type' => (string) ($data['action_type'] ?? ''),
                'target_type' => (string) ($data['target_type'] ?? 'post'),
                'target_id' => (string) ($data['target_id'] ?? ''),
                'target_url' => isset($data['target_url']) ? (string) $data['target_url'] : null,
                'action_payload' => JsonHelper::encode($payload),
                'payload_checksum' => hash('sha256', JsonHelper::encode($payload)),
                'status' => 'received',
                'attempts' => 0,
                'received_at' => current_time('mysql'),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        return $inserted !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLaravelId(int $laravelActionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE laravel_action_id = %d", $laravelActionId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function markQueued(int $laravelActionId): void
    {
        $this->updateStatus($laravelActionId, 'queued');
    }

    public function markRunning(int $laravelActionId): void
    {
        global $wpdb;
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "UPDATE {$this->table} SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE laravel_action_id = %d",
                current_time('mysql'),
                $laravelActionId
            )
        );
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function markResult(
        int $laravelActionId,
        string $status,
        ?string $error = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'status' => $status,
                'last_error' => $error,
                'before_snapshot' => $before !== null ? JsonHelper::encode($before) : null,
                'after_snapshot' => $after !== null ? JsonHelper::encode($after) : null,
                'processed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                'laravel_action_id' => $laravelActionId,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ],
            [
                '%d',
            ]
        );
    }

    public function markAcknowledged(int $laravelActionId): void
    {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'acknowledged_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                'laravel_action_id' => $laravelActionId,
            ],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function markAckPending(int $laravelActionId, ?string $error = null): void
    {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'status' => 'ack_pending',
                'last_error' => $error,
                'updated_at' => current_time('mysql'),
            ],
            [
                'laravel_action_id' => $laravelActionId,
            ],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public function markAckFailed(int $laravelActionId, string $error): void
    {
        $this->updateStatus($laravelActionId, 'ack_failed', $error);
    }

    public function updateStatus(int $laravelActionId, string $status, ?string $error = null): void
    {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'status' => $status,
                'last_error' => $error,
                'updated_at' => current_time('mysql'),
            ],
            [
                'laravel_action_id' => $laravelActionId,
            ],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByStatus(string $status, int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY updated_at ASC LIMIT %d",
                $status,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function cleanupOlderThanDays(int $days = 30): void
    {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE updated_at < DATE_SUB(%s, INTERVAL %d DAY)",
                current_time('mysql'),
                $days
            )
        );
    }
}
