<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\Storage\UrlMetaStore;
use SEOWorkerAI\Connector\Utils\Logger;

final class SocialTagsHandler extends AbstractActionHandler
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        $postId = $this->resolvePostId($action);

        // post_id=0 means a theme-rendered page (e.g. the homepage).
        // We store social tags in UrlMetaStore for these — no real post needed.
        if ($postId === 0) {
            $url = $this->resolveUrl($action);
            if ($url === '') {
                return new \WP_Error('missing_url', 'No target URL available for theme-rendered page.');
            }

            return true;
        }

        $post = get_post($postId);
        if (! $post || $post->post_status === 'trash') {
            return new \WP_Error('missing_post', 'Target post not found.');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $payload = $this->payload($action);
        $social = isset($payload['social_tags']) && is_array($payload['social_tags'])
            ? $payload['social_tags']
            : [];

        if ($social === []) {
            throw new Exception('No social tags supplied.');
        }

        // ── Theme-rendered page (post_id = 0): persist in UrlMetaStore ───────
        if ($postId === 0) {
            $url = $this->resolveUrl($action);
            $store = $this->getUrlMetaStore();

            $existing = $store->getMeta($url, 'social_tags');
            $before = ['social_tags' => is_array($existing) ? $existing : []];

            $store->setMeta($url, 'social_tags', $social);

            $after = ['social_tags' => $social];

            return [
                'status' => 'applied',
                'metadata' => [
                    'handler' => 'set_social_tags',
                    'adapter' => 'url_meta_store',
                ],
                'before' => $before,
                'after' => $after,
            ];
        }

        // ── Real post: persist in post_meta (existing behaviour) ─────────────
        $before = ['social_tags' => $this->readSocialTags($postId)];
        $this->writeSocialTags($postId, $social);
        $after = ['social_tags' => $this->readSocialTags($postId)];

        return [
            'status' => 'applied',
            'metadata' => ['handler' => 'set_social_tags'],
            'before' => $before,
            'after' => $after,
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

        if (! is_array($before) || ! isset($before['social_tags']) || ! is_array($before['social_tags'])) {
            return ['status' => 'failed', 'error' => 'Missing before snapshot'];
        }

        if ($postId === 0) {
            $url = $this->resolveUrl($action);
            $store = $this->getUrlMetaStore();
            if ($before['social_tags'] !== []) {
                $store->setMeta($url, 'social_tags', $before['social_tags']);
            } else {
                $store->deleteMeta($url, 'social_tags');
            }

            return ['status' => 'rolled_back'];
        }

        $this->writeSocialTags($postId, $before['social_tags']);

        return ['status' => 'rolled_back'];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function readSocialTags(int $postId): array
    {
        return [
            'og' => [
                'title' => (string) get_post_meta($postId, '_seoworkerai_og_title', true),
                'type' => (string) get_post_meta($postId, '_seoworkerai_og_type', true),
                'image' => (string) get_post_meta($postId, '_seoworkerai_og_image', true),
                'url' => (string) get_post_meta($postId, '_seoworkerai_og_url', true),
                'description' => (string) get_post_meta($postId, '_seoworkerai_og_description', true),
            ],
            'twitter' => [
                'card' => (string) get_post_meta($postId, '_seoworkerai_twitter_card', true),
                'site' => (string) get_post_meta($postId, '_seoworkerai_twitter_site', true),
                'title' => (string) get_post_meta($postId, '_seoworkerai_twitter_title', true),
                'description' => (string) get_post_meta($postId, '_seoworkerai_twitter_description', true),
                'image' => (string) get_post_meta($postId, '_seoworkerai_twitter_image', true),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $social
     */
    private function writeSocialTags(int $postId, array $social): void
    {
        $og = isset($social['og']) && is_array($social['og']) ? $social['og'] : [];
        $twitter = isset($social['twitter']) && is_array($social['twitter']) ? $social['twitter'] : [];

        update_post_meta($postId, '_seoworkerai_og_title', sanitize_text_field((string) ($og['title'] ?? '')));
        update_post_meta($postId, '_seoworkerai_og_type', sanitize_text_field((string) ($og['type'] ?? '')));
        update_post_meta($postId, '_seoworkerai_og_image', esc_url_raw((string) ($og['image'] ?? '')));
        update_post_meta($postId, '_seoworkerai_og_url', esc_url_raw((string) ($og['url'] ?? '')));
        update_post_meta($postId, '_seoworkerai_og_description', sanitize_text_field((string) ($og['description'] ?? '')));

        update_post_meta($postId, '_seoworkerai_twitter_card', sanitize_text_field((string) ($twitter['card'] ?? '')));
        update_post_meta($postId, '_seoworkerai_twitter_site', sanitize_text_field((string) ($twitter['site'] ?? '')));
        update_post_meta($postId, '_seoworkerai_twitter_title', sanitize_text_field((string) ($twitter['title'] ?? '')));
        update_post_meta($postId, '_seoworkerai_twitter_description', sanitize_text_field((string) ($twitter['description'] ?? '')));
        update_post_meta($postId, '_seoworkerai_twitter_image', esc_url_raw((string) ($twitter['image'] ?? '')));
    }
}
