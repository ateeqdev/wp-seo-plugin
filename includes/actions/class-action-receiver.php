<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions;

use SEOAutomation\Connector\Queue\QueueManager;
use SEOAutomation\Connector\Utils\Logger;

final class ActionReceiver
{
    private ActionRepository $repository;

    private Logger $logger;

    public function __construct(ActionRepository $repository, Logger $logger)
    {
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function receivePushAction(array $payload): array
    {
        $normalized = $this->normalizePushAction($payload);

        return $this->ingest($normalized);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function receivePulledAction(array $payload): array
    {
        $normalized = $this->normalizePulledAction($payload);

        return $this->ingest($normalized);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function ingest(array $action): array
    {
        $actionId = (int) $action['laravel_action_id'];

        if ($actionId <= 0) {
            return [
                'status' => 'rejected',
                'message' => 'Missing action_id',
            ];
        }

        if ($this->repository->existsByLaravelId($actionId)) {
            return [
                'status' => 'already_received',
                'message' => 'Action already received',
                'data' => ['action_id' => $actionId],
            ];
        }

        $inserted = $this->repository->insert($action);

        if (!$inserted) {
            $this->logger->error('action_insert_failed', [
                'entity_type' => 'action',
                'entity_id' => (string) $actionId,
                'request_payload' => $action,
            ], 'inbound');

            return [
                'status' => 'error',
                'message' => 'Failed to persist action',
            ];
        }

        $priority = isset($action['priority']) ? (int) $action['priority'] : 30;
        QueueManager::enqueueActionExecution($actionId, $priority);
        $this->repository->markQueued($actionId);

        return [
            'status' => 'accepted',
            'message' => 'Action queued for execution',
            'data' => [
                'action_id' => $actionId,
                'queued' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePushAction(array $payload): array
    {
        $target = isset($payload['target']) && is_array($payload['target']) ? $payload['target'] : [];

        return [
            'laravel_action_id' => (int) ($payload['action_id'] ?? 0),
            'action_type' => sanitize_text_field((string) ($payload['action_type'] ?? '')),
            'target_type' => sanitize_text_field((string) ($target['type'] ?? 'post')),
            'target_id' => (string) ($target['id'] ?? ''),
            'target_url' => isset($target['url']) ? esc_url_raw((string) $target['url']) : null,
            'action_payload' => isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [],
            'priority' => (int) ($payload['priority'] ?? 30),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePulledAction(array $payload): array
    {
        return [
            'laravel_action_id' => (int) ($payload['id'] ?? 0),
            'action_type' => sanitize_text_field((string) ($payload['action_type'] ?? '')),
            'target_type' => sanitize_text_field((string) ($payload['target_type'] ?? 'post')),
            'target_id' => (string) ($payload['target_id'] ?? ''),
            'target_url' => isset($payload['target_url']) ? esc_url_raw((string) $payload['target_url']) : null,
            'action_payload' => isset($payload['action_data']) && is_array($payload['action_data'])
                ? $payload['action_data']
                : [],
            'priority' => (int) ($payload['priority'] ?? 30),
        ];
    }
}
