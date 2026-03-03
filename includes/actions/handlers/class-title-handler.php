<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\SEO\InterfaceSeoAdapter;
use SEOAutomation\Connector\Utils\Logger;

final class TitleHandler extends AbstractActionHandler
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
        $post = get_post($postId);

        if (!$post) {
            throw new Exception('Post not found.');
        }

        $payload = $this->payload($action);
        $title = (string) (
            $payload['title']
            ?? $payload['recommended_title']
            ?? ($payload['title_variants'][0] ?? '')
        );
        $title = trim($title);

        if ($title === '') {
            throw new Exception('No title provided.');
        }

        $before = [
            'title' => (string) ($this->adapter->getTitle($postId) ?? ''),
            'post_title' => (string) $post->post_title,
        ];

        if (!$this->adapter->setTitle($postId, $title)) {
            throw new Exception('Adapter failed to set title.');
        }

        $mirrored = false;
        if ((bool) get_option('seoauto_mirror_post_title', false)) {
            wp_update_post([
                'ID' => $postId,
                'post_title' => $title,
            ]);
            $mirrored = true;
        }

        $after = [
            'title' => (string) ($this->adapter->getTitle($postId) ?? ''),
            'post_title' => (string) get_post_field('post_title', $postId),
            'adapter' => $this->adapter->getName(),
            'mirrored_post_title' => $mirrored,
        ];

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'title',
                'adapter' => $this->adapter->getName(),
                'mirrored_post_title' => $mirrored,
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

        $previousSeoTitle = isset($before['title']) ? (string) $before['title'] : '';
        $previousPostTitle = isset($before['post_title']) ? (string) $before['post_title'] : '';

        if ($previousSeoTitle !== '') {
            $this->adapter->setTitle($postId, $previousSeoTitle);
        } else {
            delete_post_meta($postId, '_seoauto_title');
            delete_post_meta($postId, '_yoast_wpseo_title');
            delete_post_meta($postId, '_rank_math_title');
            delete_post_meta($postId, 'rank_math_title');
            delete_post_meta($postId, '_aioseo_title');
        }

        if ($previousPostTitle !== '') {
            wp_update_post([
                'ID' => $postId,
                'post_title' => $previousPostTitle,
            ]);
        }

        return ['status' => 'rolled_back'];
    }
}
