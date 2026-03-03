<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\SEO;

final class YoastAdapter implements InterfaceSeoAdapter
{
    public function getTitle(int $postId): ?string
    {
        $value = get_post_meta($postId, '_yoast_wpseo_title', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setTitle(int $postId, string $title): bool
    {
        return (bool) update_post_meta($postId, '_yoast_wpseo_title', $title);
    }

    public function getDescription(int $postId): ?string
    {
        $value = get_post_meta($postId, '_yoast_wpseo_metadesc', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setDescription(int $postId, string $description): bool
    {
        return (bool) update_post_meta($postId, '_yoast_wpseo_metadesc', $description);
    }

    public function getCanonical(int $postId): ?string
    {
        $value = get_post_meta($postId, '_yoast_wpseo_canonical', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setCanonical(int $postId, string $url): bool
    {
        return (bool) update_post_meta($postId, '_yoast_wpseo_canonical', $url);
    }

    /**
     * @return array<string, bool>
     */
    public function getRobots(int $postId): array
    {
        return [
            'noindex' => get_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', true) === '1',
            'nofollow' => get_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow', true) === '1',
        ];
    }

    /**
     * @param array<string, bool> $robots
     */
    public function setRobots(int $postId, array $robots): bool
    {
        $ok = true;

        if (array_key_exists('noindex', $robots)) {
            $ok = $ok && (bool) update_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', $robots['noindex'] ? '1' : '0');
        }

        if (array_key_exists('nofollow', $robots)) {
            $ok = $ok && (bool) update_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow', $robots['nofollow'] ? '1' : '0');
        }

        return $ok;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchema(int $postId): ?array
    {
        $json = get_post_meta($postId, '_yoast_wpseo_schema', true);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function setSchema(int $postId, array $schema): bool
    {
        return (bool) update_post_meta($postId, '_yoast_wpseo_schema', wp_json_encode($schema));
    }

    public function getName(): string
    {
        return 'yoast';
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getSocialTags(int $postId): array
    {
        return [
            'og' => [
                'title' => $this->readFirstMeta($postId, ['_yoast_wpseo_opengraph-title', '_seoauto_og_title']),
                'type' => $this->readFirstMeta($postId, ['_yoast_wpseo_opengraph-type', '_seoauto_og_type']),
                'image' => $this->readFirstMeta($postId, ['_yoast_wpseo_opengraph-image', '_seoauto_og_image']),
                'url' => $this->readFirstMeta($postId, ['_yoast_wpseo_opengraph-url', '_seoauto_og_url']),
                'description' => $this->readFirstMeta($postId, ['_yoast_wpseo_opengraph-description', '_seoauto_og_description']),
            ],
            'twitter' => [
                'card' => $this->readFirstMeta($postId, ['_yoast_wpseo_twitter-card', '_seoauto_twitter_card']),
                'site' => $this->readFirstMeta($postId, ['_yoast_wpseo_twitter-site', '_seoauto_twitter_site']),
                'title' => $this->readFirstMeta($postId, ['_yoast_wpseo_twitter-title', '_seoauto_twitter_title']),
                'description' => $this->readFirstMeta($postId, ['_yoast_wpseo_twitter-description', '_seoauto_twitter_description']),
                'image' => $this->readFirstMeta($postId, ['_yoast_wpseo_twitter-image', '_seoauto_twitter_image']),
            ],
        ];
    }

    /**
     * @param array<int, string> $keys
     */
    private function readFirstMeta(int $postId, array $keys): string
    {
        foreach ($keys as $key) {
            $value = (string) get_post_meta($postId, $key, true);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
