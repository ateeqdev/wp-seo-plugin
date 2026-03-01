<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Utils;

final class LockManager
{
    /**
     * @var array<string, string>
     */
    private array $owners = [];

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function acquire(string $key, int $ttl = 300): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'seoauto_locks';
        $owner = wp_generate_uuid4();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);

        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'lock_key' => $key,
                'owner' => $owner,
                'expires_at' => $expiresAt,
                'created_at' => current_time('mysql'),
            ]
        );

        if ($inserted !== false) {
            $this->owners[$key] = $owner;
            return true;
        }

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE lock_key = %s AND expires_at < %s",
                $key,
                current_time('mysql')
            )
        );

        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'lock_key' => $key,
                'owner' => $owner,
                'expires_at' => $expiresAt,
                'created_at' => current_time('mysql'),
            ]
        );

        if ($inserted !== false) {
            $this->owners[$key] = $owner;
            return true;
        }

        $this->logger->warning('lock_acquire_failed', ['entity_id' => $key]);

        return false;
    }

    public function release(string $key): void
    {
        if (!isset($this->owners[$key])) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_locks';

        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $table,
            [
                'lock_key' => $key,
                'owner' => $this->owners[$key],
            ],
            ['%s', '%s']
        );

        unset($this->owners[$key]);
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->owners) as $key) {
            $this->release($key);
        }
    }
}
