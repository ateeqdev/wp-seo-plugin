<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions;

use SEOAutomation\Connector\API\LaravelClient;
use SEOAutomation\Connector\Queue\QueueManager;
use SEOAutomation\Connector\Utils\JsonHelper;
use SEOAutomation\Connector\Utils\Logger;

final class StatusReporter
{
    private LaravelClient $client;

    private ActionRepository $repository;

    private Logger $logger;

    public function __construct(LaravelClient $client, ActionRepository $repository, Logger $logger)
    {
        $this->client = $client;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $metadata
     */
    public function report(array $action, string $status, array $metadata = [], ?string $errorMessage = null): void
    {
        $siteId = (int) get_option('seoauto_site_id', 0);
        $actionId = (int) ($action['laravel_action_id'] ?? 0);

        if ($siteId <= 0 || $actionId <= 0) {
            return;
        }

        $payload = [
            'status' => $status,
        ];

        if ($status === 'applied') {
            $payload['applied_at'] = gmdate('c');
        }

        if ($errorMessage !== null && $errorMessage !== '') {
            $payload['error_message'] = $errorMessage;
        }

        if (!empty($metadata)) {
            $payload['metadata'] = $metadata;
        }

        try {
            $response = $this->client->reportActionStatus($siteId, $actionId, $payload);
            if (!empty($response['acknowledged'])) {
                $this->repository->markAcknowledged($actionId);
                return;
            }

            $this->repository->markAckPending($actionId, 'Missing acknowledged=true in response');
            QueueManager::scheduleAckRetry($actionId);
        } catch (\Throwable $exception) {
            $this->repository->markAckPending($actionId, $exception->getMessage());
            QueueManager::scheduleAckRetry($actionId);
            $this->logger->warning('action_ack_pending', [
                'entity_type' => 'action',
                'entity_id' => (string) $actionId,
                'error' => $exception->getMessage(),
                'request_payload' => $payload,
            ], 'outbound');
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function retryAck(array $args): void
    {
        $actionId = isset($args['action_id']) ? (int) $args['action_id'] : 0;

        if ($actionId <= 0) {
            return;
        }

        $action = $this->repository->findByLaravelId($actionId);
        if ($action === null) {
            return;
        }

        $status = (string) ($action['status'] ?? 'failed');
        if ($status === 'ack_pending') {
            $after = JsonHelper::decodeArray(isset($action['after_snapshot']) ? (string) $action['after_snapshot'] : '');
            $payloadStatus = isset($after['status']) ? (string) $after['status'] : 'applied';
            $this->report($action, $payloadStatus, $after, isset($action['last_error']) ? (string) $action['last_error'] : null);
            return;
        }

        if (in_array($status, ['applied', 'failed', 'rejected'], true)) {
            $after = JsonHelper::decodeArray(isset($action['after_snapshot']) ? (string) $action['after_snapshot'] : '');
            $this->report($action, $status, $after, isset($action['last_error']) ? (string) $action['last_error'] : null);
        }
    }
}
