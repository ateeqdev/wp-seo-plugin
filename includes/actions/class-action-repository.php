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
                'auto_apply' => !empty($data['auto_apply']) ? 1 : 0,
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
                '%d',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($inserted !== false) {
            $this->logChange(
                laravelActionId: (int) ($data['laravel_action_id'] ?? 0),
                eventType: 'received',
                status: 'received',
                note: 'Action received from Laravel.'
            );
        }

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
        $this->logChange($laravelActionId, 'queued', 'queued', 'Action queued for execution.');
    }

    public function markAwaitingReview(int $laravelActionId): void
    {
        $this->updateStatus($laravelActionId, 'received');
        $this->logChange($laravelActionId, 'queued', 'received', 'Action awaiting manual review.');
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
        $this->logChange($laravelActionId, 'queued', 'running', 'Action execution started.');
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

        $eventType = match ($status) {
            'applied' => 'applied',
            'failed' => 'failed',
            'rejected' => 'rejected',
            'rolled_back' => 'reverted',
            default => 'failed',
        };

        $this->logChange(
            laravelActionId: $laravelActionId,
            eventType: $eventType,
            status: $status,
            note: $error,
            before: $before,
            after: $after
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

    public function updatePayload(int $laravelActionId, array $newPayload, int $userId = 0): bool
    {
        global $wpdb;

        $encoded = JsonHelper::encode($newPayload);
        $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->table,
            [
                'action_payload' => $encoded,
                'payload_checksum' => hash('sha256', $encoded),
                'reviewed_by' => $userId > 0 ? $userId : null,
                'reviewed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                'laravel_action_id' => $laravelActionId,
            ],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return false;
        }

        $this->logChange($laravelActionId, 'edited', 'received', 'Action payload edited by admin.', [], $newPayload);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActions(array $filters = [], int $limit = 100): array
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }

        if (!isset($filters['include_human_actions']) || !$filters['include_human_actions']) {
            $where[] = 'action_type != %s';
            $params[] = 'human-action-required';
        }

        $whereSql = implode(' AND ', $where);
        $params[] = max(1, $limit);

        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE {$whereSql} ORDER BY received_at DESC LIMIT %d",
            ...$params
        );
        $rows = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listChangeLogs(int $laravelActionId = 0, int $limit = 200, array $filters = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_change_logs';
        $where = ['1=1'];
        $params = [];

        if ($laravelActionId > 0) {
            $where[] = 'laravel_action_id = %d';
            $params[] = $laravelActionId;
        }

        if (!empty($filters['exclude_event_type'])) {
            $where[] = 'event_type != %s';
            $params[] = (string) $filters['exclude_event_type'];
        }

        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = %s';
            $params[] = (string) $filters['event_type'];
        }

        $params[] = max(1, $limit);
        $whereSql = implode(' AND ', $where);
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY created_at DESC LIMIT %d",
            ...$params
        );

        $rows = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addAdminActionItem(int $laravelActionId, array $payload): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_admin_action_items';
        $action = $this->findByLaravelId($laravelActionId);
        $payloadTitle = sanitize_text_field((string) ($payload['title'] ?? 'Manual action required'));
        $contextPrefix = '';
        $targetType = (string) ($action['target_type'] ?? '');
        $targetId = (string) ($action['target_id'] ?? '');

        if ($targetType === 'post' && ctype_digit($targetId)) {
            $postTitle = get_the_title((int) $targetId);
            if (is_string($postTitle) && trim($postTitle) !== '') {
                $contextPrefix = trim($postTitle);
            }
        }

        if ($contextPrefix === '' && isset($payload['page_title'])) {
            $contextPrefix = trim((string) $payload['page_title']);
        }

        $itemTitle = $payloadTitle;
        if ($contextPrefix !== '' && stripos($itemTitle, $contextPrefix) === false) {
            $itemTitle = "{$contextPrefix} - {$itemTitle}";
        }

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'action_id' => isset($action['id']) ? (int) $action['id'] : null,
                'laravel_action_id' => $laravelActionId,
                'site_id' => (int) get_option('seoauto_site_id', 0),
                'title' => sanitize_text_field($itemTitle),
                'details' => sanitize_textarea_field((string) ($payload['details'] ?? '')),
                'recommended_value' => sanitize_text_field((string) ($payload['recommended_value'] ?? '')),
                'category' => sanitize_text_field((string) ($payload['category'] ?? 'general')),
                'status' => 'open',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->logChange(
            laravelActionId: $laravelActionId,
            eventType: 'human_action_created',
            status: 'applied',
            note: 'Human action item created.',
            after: $payload
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

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function logChange(
        int $laravelActionId,
        string $eventType,
        string $status,
        ?string $note = null,
        array $before = [],
        array $after = []
    ): void {
        if ($laravelActionId <= 0) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_change_logs';
        $action = $this->findByLaravelId($laravelActionId);
        $actionId = isset($action['id']) ? (int) $action['id'] : null;

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'action_id' => $actionId,
                'laravel_action_id' => $laravelActionId,
                'event_type' => $eventType,
                'status' => $status,
                'actor_user_id' => get_current_user_id() ?: null,
                'note' => $note,
                'before_snapshot' => empty($before) ? null : JsonHelper::encode($before),
                'after_snapshot' => empty($after) ? null : JsonHelper::encode($after),
                'metadata' => null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }
}
