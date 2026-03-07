<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\SEO\InterfaceSeoAdapter;
use SEOWorkerAI\Connector\Utils\Logger;

final class MetaDescriptionHandler extends AbstractActionHandler
{
    private InterfaceSeoAdapter $adapter;

    public function __construct(InterfaceSeoAdapter $adapter, Logger $logger)
    {
        parent::__construct($logger);
        $this->adapter = $adapter;
    }

    /**
     * @param array<string, mixed> $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        $postId = $this->resolvePostId($action);
        $post = get_post($postId);

        if (!$post || $post->post_status === 'trash') {
            return new \WP_Error('missing_post', 'Target post not found.');
        }

        return true;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $payload = $this->payload($action);

        $description = (string) (
            $payload['meta_description']
            ?? $payload['recommended_meta_description']
            ?? ($payload['meta_description_variants'][0] ?? '')
        );

        $description = trim($description);

        if ($description === '') {
            throw new Exception('No meta description provided.');
        }

        $length = strlen($description);
        if ($length > 320) {
            throw new Exception('Meta description must be 320 characters or fewer.');
        }

        $before = [
            'meta_description' => (string) ($this->adapter->getDescription($postId) ?? ''),
        ];

        if (trim($before['meta_description']) === trim($description)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true],
                'before' => $before,
                'after' => $before,
            ];
        }

        if (!$this->adapter->setDescription($postId, $description)) {
            throw new Exception('Adapter failed to set description.');
        }

        $after = [
            'meta_description' => (string) ($this->adapter->getDescription($postId) ?? ''),
            'adapter' => $this->adapter->getName(),
        ];

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'meta_description',
                'adapter' => $this->adapter->getName(),
            ],
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $rawBefore = isset($action['before_snapshot']) ? (string) $action['before_snapshot'] : '';
        $before = json_decode($rawBefore, true);

        if (!is_array($before)) {
            return ['status' => 'failed', 'error' => 'Missing before snapshot'];
        }

        $previous = isset($before['meta_description']) ? (string) $before['meta_description'] : '';

        if ($previous !== '') {
            $this->adapter->setDescription($postId, $previous);
        } else {
            delete_post_meta($postId, '_seoworkerai_meta_description');
            delete_post_meta($postId, '_yoast_wpseo_metadesc');
            delete_post_meta($postId, '_rank_math_description');
            delete_post_meta($postId, 'rank_math_description');
            delete_post_meta($postId, '_aioseo_description');
        }

        return ['status' => 'rolled_back'];
    }
}
