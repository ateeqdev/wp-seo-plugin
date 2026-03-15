<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\SEO\InterfaceSeoAdapter;
use SEOWorkerAI\Connector\Utils\Logger;

final class TitleHandler extends AbstractActionHandler
{
    private InterfaceSeoAdapter $adapter;

    public function __construct(InterfaceSeoAdapter $adapter, Logger $logger)
    {
        parent::__construct($logger);
        $this->adapter = $adapter;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        return $this->validatePostOrUrlTarget($action);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $post = get_post($postId);

        if (! $post && $postId > 0) {
            throw new Exception('Post not found.');
        }

        $payload = $this->payload($action);

        // Prefer recommended_title (the actual optimised SEO value) over title
        // which is the recommendation label e.g. "Policy - Expand page title".
        $title = trim((string) (
            $payload['recommended_title']
            ?? ($payload['title_variants'][0] ?? null)
            ?? $payload['title']
            ?? ''
        ));

        if ($title === '') {
            throw new Exception('No title provided.');
        }

        // ── URL meta store path (theme-rendered / post_id=0 pages) ───────────
        $url = $this->resolveUrl($action);
        if ($postId === 0 && $url !== '') {
            $store = $this->getUrlMetaStore();
            $beforeTitle = (string) ($store->getMeta($url, 'title') ?? '');

            if (trim($beforeTitle) === trim($title)) {
                return $this->identicalResult(
                    ['title' => $beforeTitle, 'post_title' => ''],
                    'title already matches stored value in url_meta_store'
                );
            }

            $store->setMeta($url, 'title', $title);

            return [
                'status' => 'applied',
                'metadata' => [
                    'handler' => 'title',
                    'adapter' => 'url_meta_store',
                    'mirrored_post_title' => false,
                ],
                'before' => ['title' => $beforeTitle, 'post_title' => ''],
                'after' => [
                    'title' => $title,
                    'post_title' => '',
                    'adapter' => 'url_meta_store',
                    'mirrored_post_title' => false,
                ],
            ];
        }

        if (! $post) {
            throw new Exception('Post not found.');
        }

        // ── Real post path ────────────────────────────────────────────────────
        $currentTitle = (string) ($this->adapter->getTitle($postId) ?? '');
        $before = [
            'title' => $currentTitle,
            'post_title' => (string) $post->post_title,
        ];

        if (trim($currentTitle) === trim($title)) {
            return $this->identicalResult(
                $before,
                'title already matches value stored by adapter'
            );
        }

        if (! $this->adapter->setTitle($postId, $title)) {
            throw new Exception('Adapter failed to set title.');
        }

        $mirrored = false;
        if ((bool) get_option('seoworkerai_mirror_post_title', false)) {
            wp_update_post(['ID' => $postId, 'post_title' => $title]);
            $mirrored = true;
        }

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'title',
                'adapter' => $this->adapter->getName(),
                'mirrored_post_title' => $mirrored,
            ],
            'before' => $before,
            'after' => [
                'title' => (string) ($this->adapter->getTitle($postId) ?? ''),
                'post_title' => (string) get_post_field('post_title', $postId),
                'adapter' => $this->adapter->getName(),
                'mirrored_post_title' => $mirrored,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $rawBefore = isset($action['before_snapshot']) ? (string) $action['before_snapshot'] : '';
        $before = json_decode($rawBefore, true);

        if (! is_array($before)) {
            return ['status' => 'failed', 'error' => 'Missing before snapshot'];
        }

        $previousSeoTitle = isset($before['title']) ? (string) $before['title'] : '';
        $previousPostTitle = isset($before['post_title']) ? (string) $before['post_title'] : '';

        $url = $this->resolveUrl($action);
        if ($postId === 0 && $url !== '') {
            $store = $this->getUrlMetaStore();
            if ($previousSeoTitle !== '') {
                $store->setMeta($url, 'title', $previousSeoTitle);
            } else {
                $store->deleteMeta($url, 'title');
            }

            return ['status' => 'rolled_back'];
        }

        if ($previousSeoTitle !== '') {
            $this->adapter->setTitle($postId, $previousSeoTitle);
        } else {
            delete_post_meta($postId, '_seoworkerai_title');
            delete_post_meta($postId, '_yoast_wpseo_title');
            delete_post_meta($postId, '_rank_math_title');
            delete_post_meta($postId, 'rank_math_title');
            delete_post_meta($postId, '_aioseo_title');
        }

        if ($previousPostTitle !== '') {
            wp_update_post(['ID' => $postId, 'post_title' => $previousPostTitle]);
        }

        return ['status' => 'rolled_back'];
    }
}
