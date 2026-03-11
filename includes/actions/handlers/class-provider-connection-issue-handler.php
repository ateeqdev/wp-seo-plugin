<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use SEOWorkerAI\Connector\Utils\Logger;

final class ProviderConnectionIssueHandler extends AbstractActionHandler
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $payload = $this->payload($action);
        $providerName = sanitize_text_field((string) ($payload['provider_name'] ?? 'unknown_provider'));
        $issueType = sanitize_text_field((string) ($payload['issue_type'] ?? 'authorization'));
        $taskName = sanitize_text_field((string) ($payload['task_name'] ?? 'unknown'));
        $message = sanitize_text_field((string) ($payload['message'] ?? 'Provider access issue detected by SEOWorkerAI.'));
        $resolutionHint = sanitize_text_field((string) ($payload['resolution_hint'] ?? 'Reconnect provider credentials and verify ownership.'));
        $statusCode = isset($payload['status_code']) && is_numeric($payload['status_code'])
            ? (int) $payload['status_code']
            : null;

        $alerts = get_option('seoworkerai_provider_connection_alerts', []);
        if (!is_array($alerts)) {
            $alerts = [];
        }

        $alertKey = $providerName . ':' . $issueType;
        $existing = isset($alerts[$alertKey]) && is_array($alerts[$alertKey]) ? $alerts[$alertKey] : [];
        $occurrences = max((int) ($existing['occurrences'] ?? 0), 0) + 1;

        $next = [
            'provider_name' => $providerName,
            'issue_type' => $issueType,
            'task_name' => $taskName,
            'message' => $message,
            'resolution_hint' => $resolutionHint,
            'status_code' => $statusCode,
            'first_detected_at' => (string) ($existing['first_detected_at'] ?? current_time('mysql')),
            'last_detected_at' => current_time('mysql'),
            'occurrences' => $occurrences,
        ];

        $alerts[$alertKey] = $next;
        update_option('seoworkerai_provider_connection_alerts', $alerts, false);

        $this->logger->warning('provider_connection_issue_received', [
            'entity_type' => 'provider',
            'entity_id' => $providerName,
            'request_payload' => [
                'provider_name' => $providerName,
                'issue_type' => $issueType,
                'task_name' => $taskName,
                'status_code' => $statusCode,
            ],
            'error' => $message,
            'after' => $next,
        ], 'inbound');

        return [
            'status' => 'applied',
            'metadata' => [
                'provider_name' => $providerName,
                'issue_type' => $issueType,
                'occurrences' => $occurrences,
            ],
            'before' => $existing,
            'after' => $next,
        ];
    }
}
