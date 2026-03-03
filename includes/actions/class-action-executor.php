<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions;

use SEOAutomation\Connector\Actions\Handlers\AltTextHandler;
use SEOAutomation\Connector\Actions\Handlers\BrokenLinkHandler;
use SEOAutomation\Connector\Actions\Handlers\CanonicalHandler;
use SEOAutomation\Connector\Actions\Handlers\HeadingHandler;
use SEOAutomation\Connector\Actions\Handlers\HumanActionRequiredHandler;
use SEOAutomation\Connector\Actions\Handlers\IndexingHandler;
use SEOAutomation\Connector\Actions\Handlers\InternalLinkHandler;
use SEOAutomation\Connector\Actions\Handlers\InterfaceActionHandler;
use SEOAutomation\Connector\Actions\Handlers\MetaDescriptionHandler;
use SEOAutomation\Connector\Actions\Handlers\MonitorHandler;
use SEOAutomation\Connector\Actions\Handlers\PostDatesHandler;
use SEOAutomation\Connector\Actions\Handlers\ProviderConnectionIssueHandler;
use SEOAutomation\Connector\Actions\Handlers\RedirectHandler;
use SEOAutomation\Connector\Actions\Handlers\RobotsHandler;
use SEOAutomation\Connector\Actions\Handlers\SchemaHandler;
use SEOAutomation\Connector\Actions\Handlers\SitemapHandler;
use SEOAutomation\Connector\Actions\Handlers\SocialTagsHandler;
use SEOAutomation\Connector\Actions\Handlers\TechnicalFlagsHandler;
use SEOAutomation\Connector\Actions\Handlers\TitleHandler;
use SEOAutomation\Connector\SEO\SeoDetector;
use SEOAutomation\Connector\Utils\LockManager;
use SEOAutomation\Connector\Utils\Logger;

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
            'add_alt_text' => new AltTextHandler($logger),
            'add_meta_description' => new MetaDescriptionHandler($adapter, $logger),
            'update_meta_description' => new MetaDescriptionHandler($adapter, $logger),
            'update_title' => new TitleHandler($adapter, $logger),
            'add_canonical' => new CanonicalHandler($adapter, $logger),
            'add_schema_markup' => new SchemaHandler($adapter, $logger),
            'add_schema' => new SchemaHandler($adapter, $logger),
            'add_redirect' => new RedirectHandler($logger),
            'fix_broken_link' => new BrokenLinkHandler($logger),
            'add_internal_link' => new InternalLinkHandler($logger),
            'adjust_headings' => new HeadingHandler($logger),
            'technical_seo_flags' => new TechnicalFlagsHandler($adapter, $logger),
            'sitemap_update' => new SitemapHandler($logger),
            'robots_directives' => new RobotsHandler($logger),
            'submit_for_indexing' => new IndexingHandler($logger),
            'monitor_only' => new MonitorHandler($logger),
            'provider_connection_issue' => new ProviderConnectionIssueHandler($logger),
            'set_social_tags' => new SocialTagsHandler($logger),
            'set_post_dates' => new PostDatesHandler($logger),
            'human_action_required' => new HumanActionRequiredHandler($repository, $logger),
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

            $this->repository->markResult($laravelActionId, $rolledBack ? 'rolled_back' : 'failed', $exception->getMessage());
            $this->statusReporter->report($action, 'failed', [
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
        }

        return $result;
    }
}
