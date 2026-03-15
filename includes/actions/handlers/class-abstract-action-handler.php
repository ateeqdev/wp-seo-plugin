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
        $this->rollbackManager = $rollbackManager ?? new RollbackManager;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        return ['status' => 'rolled_back'];
    }

    /**
     * For SEO Meta tags: Validates that we either have a valid Post OR a fallback URL.
     *
     * @param  array<string, mixed>  $action
     * @return bool|\WP_Error
     */
    protected function validatePostOrUrlTarget(array $action)
    {
        $postId = $this->resolvePostId($action);

        // If post ID is 0, we MUST have a target URL for UrlMetaStore
        if ($postId === 0) {
            $url = $this->resolveUrl($action);
            if ($url === '') {
                return new \WP_Error('missing_target', 'No valid post ID or URL provided.');
            }

            return true;
        }

        return $this->validateStrictPostTarget($action);
    }

    /**
     * For Content mutations: Validates that we strictly have a valid, non-trashed Post.
     *
     * @param  array<string, mixed>  $action
     * @return bool|\WP_Error
     */
    protected function validateStrictPostTarget(array $action)
    {
        $postId = $this->resolvePostId($action);
        if ($postId <= 0) {
            return new \WP_Error('missing_target', 'Target post ID is missing or invalid.');
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post || $post->post_status === 'trash') {
            return new \WP_Error('missing_post', 'Target post not found or is in trash.');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    protected function payload(array $action): array
    {
        return JsonHelper::decodeArray(isset($action['action_payload']) ? (string) $action['action_payload'] : '');
    }

    protected function resolvePostId(array $action): int
    {
        $target = isset($action['target_id']) ? (int) $action['target_id'] : 0;

        if ($target === 0 && ! empty($action['target_url'])) {
            $target = url_to_postid($action['target_url']);
        }

        if ($target > 0) {
            return $target;
        }

        $data = $this->payload($action);

        $target = isset($data['post_id']) ? (int) $data['post_id'] : 0;

        if ($target === 0 && ! empty($data['post_url'])) {
            $target = url_to_postid($data['post_url']);
        }

        return $target;
    }

    protected function resolveUrl(array $action): string
    {
        $url = isset($action['target_url']) ? trim((string) $action['target_url']) : '';
        if ($url !== '') {
            return $url;
        }

        $data = $this->payload($action);

        return isset($data['post_url']) ? trim((string) $data['post_url']) : '';
    }

    protected function getUrlMetaStore(): \SEOWorkerAI\Connector\Storage\UrlMetaStore
    {
        return new \SEOWorkerAI\Connector\Storage\UrlMetaStore;
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
     * @param  array<string, mixed>  $snapshot
     */
    protected function restorePostSnapshot(int $postId, array $snapshot): bool
    {
        return $this->rollbackManager->restorePostSnapshot($postId, $snapshot);
    }
}
