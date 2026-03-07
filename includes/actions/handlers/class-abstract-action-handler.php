<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use SEOWorkerAI\Connector\Content\RollbackManager;
use SEOWorkerAI\Connector\Utils\JsonHelper;
use SEOWorkerAI\Connector\Utils\Logger;

abstract class AbstractActionHandler implements InterfaceActionHandler
{
    protected Logger $logger;

    protected RollbackManager $rollbackManager;

    public function __construct(Logger $logger, ?RollbackManager $rollbackManager = null)
    {
        $this->logger = $logger;
        $this->rollbackManager = $rollbackManager ?? new RollbackManager();
    }

    /**
     * @param array<string, mixed> $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        return true;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        return ['status' => 'rolled_back'];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    protected function payload(array $action): array
    {
        return JsonHelper::decodeArray(isset($action['action_payload']) ? (string) $action['action_payload'] : '');
    }

    protected function resolvePostId(array $action): int
    {
        $target = isset($action['target_id']) ? (int) $action['target_id'] : 0;

        if ($target > 0) {
            return $target;
        }

        $data = $this->payload($action);

        return isset($data['post_id']) ? (int) $data['post_id'] : 0;
    }

    protected function sanitizeText(string $value): string
    {
        return trim(wp_strip_all_tags($value));
    }

    /**
     * @return array<string, mixed>
     */
    protected function capturePostSnapshot(int $postId): array
    {
        return $this->rollbackManager->capturePostSnapshot($postId);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    protected function restorePostSnapshot(int $postId, array $snapshot): bool
    {
        return $this->rollbackManager->restorePostSnapshot($postId, $snapshot);
    }
}
