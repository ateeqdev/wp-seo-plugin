<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Utils;

final class Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $eventName, array $context = [], string $source = 'executor'): void
    {
        if (!((bool) get_option('seoauto_debug_enabled', false))) {
            return;
        }

        $this->log('debug', $eventName, $context, $source);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $eventName, array $context = [], string $source = 'executor'): void
    {
        $this->log('info', $eventName, $context, $source);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $eventName, array $context = [], string $source = 'executor'): void
    {
        $this->log('warning', $eventName, $context, $source);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $eventName, array $context = [], string $source = 'executor'): void
    {
        $this->log('error', $eventName, $context, $source);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $severity, string $eventName, array $context, string $source): void
    {
        $correlationId = (string) ($context['correlation_id'] ?? wp_generate_uuid4());

        $entry = [
            'correlation_id' => $correlationId,
            'source' => in_array($source, ['inbound', 'outbound', 'executor', 'admin'], true) ? $source : 'executor',
            'severity' => in_array($severity, ['debug', 'info', 'warning', 'error'], true) ? $severity : 'info',
            'event_name' => sanitize_text_field($eventName),
            'entity_type' => isset($context['entity_type']) ? sanitize_text_field((string) $context['entity_type']) : null,
            'entity_id' => isset($context['entity_id']) ? (string) $context['entity_id'] : null,
            'request_payload' => isset($context['request_payload']) ? JsonHelper::encode($context['request_payload']) : null,
            'response_payload' => isset($context['response_payload']) ? JsonHelper::encode($context['response_payload']) : null,
            'before_value' => isset($context['before']) ? JsonHelper::encode($context['before']) : null,
            'after_value' => isset($context['after']) ? JsonHelper::encode($context['after']) : null,
            'error_message' => isset($context['error']) ? sanitize_text_field((string) $context['error']) : null,
            'created_at' => current_time('mysql'),
        ];

        global $wpdb;
        $table = $wpdb->prefix . 'seoauto_execution_logs';

        $inserted = $wpdb->insert($table, $entry); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ($inserted === false) {
            error_log('[seoauto][' . $severity . '] ' . $eventName . ' ' . JsonHelper::encode($context)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
