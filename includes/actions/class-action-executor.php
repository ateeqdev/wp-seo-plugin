<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions;

use SEOWorkerAI\Connector\Actions\Handlers\AltTextHandler;
use SEOWorkerAI\Connector\Actions\Handlers\BrokenLinkHandler;
use SEOWorkerAI\Connector\Actions\Handlers\CanonicalHandler;
use SEOWorkerAI\Connector\Actions\Handlers\HeadingHandler;
use SEOWorkerAI\Connector\Actions\Handlers\HumanActionRequiredHandler;
use SEOWorkerAI\Connector\Actions\Handlers\IndexingHandler;
use SEOWorkerAI\Connector\Actions\Handlers\InternalLinkHandler;
use SEOWorkerAI\Connector\Actions\Handlers\InterfaceActionHandler;
use SEOWorkerAI\Connector\Actions\Handlers\MetaDescriptionHandler;
use SEOWorkerAI\Connector\Actions\Handlers\MonitorHandler;
use SEOWorkerAI\Connector\Actions\Handlers\PostDatesHandler;
use SEOWorkerAI\Connector\Actions\Handlers\RedirectHandler;
use SEOWorkerAI\Connector\Actions\Handlers\RobotsHandler;
use SEOWorkerAI\Connector\Actions\Handlers\SchemaHandler;
use SEOWorkerAI\Connector\Actions\Handlers\SitemapHandler;
use SEOWorkerAI\Connector\Actions\Handlers\SocialTagsHandler;
use SEOWorkerAI\Connector\Actions\Handlers\TechnicalFlagsHandler;
use SEOWorkerAI\Connector\Actions\Handlers\TitleHandler;
use SEOWorkerAI\Connector\SEO\SeoDetector;
use SEOWorkerAI\Connector\Utils\LockManager;
use SEOWorkerAI\Connector\Utils\Logger;

final class ActionExecutor
{
    private ActionRepository $repository;

    private StatusReporter $statusReporter;

    private LockManager $lockManager;

    private Logger $logger;

    /**
     * @var array<string, InterfaceActionHandler>
     */
    private array $handlers = [];

    public function __construct(
        ActionRepository $repository,
        StatusReporter $statusReporter,
        LockManager $lockManager,
        Logger $logger
    ) {
        $this->repository = $repository;
        $this->statusReporter = $statusReporter;
        $this->lockManager = $lockManager;
        $this->logger = $logger;

        $adapter = SeoDetector::instance()->getAdapter();

        $this->handlers = [
            'add-alt-text' => new AltTextHandler($logger),
            'add-meta-description' => new MetaDescriptionHandler($adapter, $logger),
            'update-meta-description' => new MetaDescriptionHandler($adapter, $logger),
            'update-title' => new TitleHandler($adapter, $logger),
            'add-canonical' => new CanonicalHandler($adapter, $logger),
            'add-schema-markup' => new SchemaHandler($adapter, $logger),
            'add-schema' => new SchemaHandler($adapter, $logger),
            'add-redirect' => new RedirectHandler($logger),
            'fix-broken-link' => new BrokenLinkHandler($logger),
            'add-internal-link' => new InternalLinkHandler($logger),
            'adjust-headings' => new HeadingHandler($logger),
            'technical-seo-flags' => new TechnicalFlagsHandler($adapter, $logger),
            'sitemap-update' => new SitemapHandler($logger),
            'robots-directives' => new RobotsHandler($logger),
            'submit-for-indexing' => new IndexingHandler($logger),
            'monitor-only' => new MonitorHandler($logger),
            'set-social-tags' => new SocialTagsHandler($logger),
            'set-post-dates' => new PostDatesHandler($logger),
            'human-action-required' => new HumanActionRequiredHandler($repository, $logger),
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function executeByArgs(array $args): void
    {
        $actionId = isset($args['action_id']) ? (int) $args['action_id'] : 0;

        if ($actionId <= 0) {
            return;
        }

        $this->executeByLaravelId($actionId);
    }

    public function executeByLaravelId(int $laravelActionId): void
    {
        $action = $this->repository->findByLaravelId($laravelActionId);

        if ($action === null) {
            return;
        }

        $status = (string) ($action['status'] ?? 'received');
        if ($this->hasAlreadyAppliedPayload($action)) {
            $this->repository->markResult($laravelActionId, 'applied', null);
            $this->statusReporter->report($action, 'applied', [
                'noop' => true,
                'reason' => 'payload_already_applied',
            ]);
            return;
        }

        if (in_array($status, ['applied', 'rejected', 'running'], true)) {
            return;
        }

        $actionLock = 'action:' . $laravelActionId;
        $entityLock = sprintf(
            '%s:%s',
            (string) ($action['target_type'] ?? 'target'),
            (string) ($action['target_id'] ?? '0')
        );

        if (!$this->lockManager->acquire($actionLock, 300)) {
            return;
        }

        if (!$this->lockManager->acquire($entityLock, 300)) {
            $this->lockManager->release($actionLock);
            return;
        }

        $handler = null;

        try {
            $this->repository->markRunning($laravelActionId);

            $handler = $this->resolveHandler((string) ($action['action_type'] ?? ''));
            if ($handler === null) {
                $this->repository->markResult($laravelActionId, 'rejected', 'Unsupported action type');
                $this->statusReporter->report($action, 'rejected', [
                    'reason' => 'unsupported_action_type',
                    'action_type' => (string) ($action['action_type'] ?? ''),
                ]);
                return;
            }

            $validation = $handler->validate($action);
            if (is_wp_error($validation)) {
                $message = $validation->get_error_message();
                $this->repository->markResult($laravelActionId, 'rejected', $message);
                $this->statusReporter->report($action, 'rejected', [
                    'reason' => 'validation_failed',
                    'message' => $message,
                ], $message);
                return;
            }

            $result = $handler->execute($action);
            $resultStatus = (string) ($result['status'] ?? 'applied');
            $metadata = isset($result['metadata']) && is_array($result['metadata']) ? $result['metadata'] : [];
            $before = isset($result['before']) && is_array($result['before']) ? $result['before'] : [];
            $after = isset($result['after']) && is_array($result['after']) ? $result['after'] : [];
            $error = isset($result['error_message']) ? (string) $result['error_message'] : null;

            $this->repository->markResult($laravelActionId, $resultStatus, $error, $before, $after);
            $this->statusReporter->report($action, $resultStatus, $metadata, $error);
        } catch (\Throwable $exception) {
            $rolledBack = false;

            if ($handler instanceof InterfaceActionHandler) {
                try {
                    $rollbackResult = $handler->rollback($action);
                    $rolledBack = (($rollbackResult['status'] ?? '') === 'rolled_back');
                } catch (\Throwable $rollbackException) {
                    $this->logger->warning('action_rollback_failed', [
                        'entity_type' => 'action',
                        'entity_id' => (string) $laravelActionId,
                        'error' => $rollbackException->getMessage(),
                    ]);
                }
            }

            $finalStatus = $rolledBack ? 'rolled_back' : 'failed';
            $this->repository->markResult($laravelActionId, $finalStatus, $exception->getMessage());
            $this->statusReporter->report($action, $finalStatus, [
                'reason' => 'execution_failed',
                'rolled_back' => $rolledBack,
            ], $exception->getMessage());
            $this->logger->error('action_execution_failed', [
                'entity_type' => 'action',
                'entity_id' => (string) $laravelActionId,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            $this->lockManager->release($entityLock);
            $this->lockManager->release($actionLock);
        }
    }

    private function resolveHandler(string $actionType): ?InterfaceActionHandler
    {
        return $this->handlers[$actionType] ?? null;
    }

    /**
     * @param array<string, mixed> $action
     */
    private function hasAlreadyAppliedPayload(array $action): bool
    {
        $status = (string) ($action['status'] ?? 'received');

        if ($status === 'rolled_back') {
            return false;
        }

        $lastAppliedChecksum = (string) ($action['last_applied_checksum'] ?? '');
        $payloadChecksum = (string) ($action['payload_checksum'] ?? '');

        return $lastAppliedChecksum !== '' && hash_equals($lastAppliedChecksum, $payloadChecksum);
    }

    public function revertByLaravelId(int $laravelActionId): array
    {
        $action = $this->repository->findByLaravelId($laravelActionId);
        if ($action === null) {
            return ['status' => 'failed', 'error' => 'Action not found'];
        }

        $handler = $this->resolveHandler((string) ($action['action_type'] ?? ''));
        if (!$handler instanceof InterfaceActionHandler) {
            return ['status' => 'failed', 'error' => 'Unsupported action type'];
        }

        $result = $handler->rollback($action);
        $status = (string) ($result['status'] ?? 'failed');

        if ($status === 'rolled_back') {
            $this->repository->markResult($laravelActionId, 'rolled_back', null);
            $this->statusReporter->report($action, 'rolled_back', [
                'reason' => 'manual_revert',
            ]);
        }

        return $result;
    }
}
