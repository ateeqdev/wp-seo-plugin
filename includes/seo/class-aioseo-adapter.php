<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\SEO;

final class AioseoAdapter implements InterfaceSeoAdapter
{
    public function getTitle(int $postId): ?string
    {
        if (function_exists('aioseo') && isset(aioseo()->meta) && method_exists(aioseo()->meta, 'post')) {
            $meta = aioseo()->meta->post->get($postId);
            if ($meta && !empty($meta->title)) {
                return (string) $meta->title;
            }
        }

        $value = get_post_meta($postId, '_aioseo_title', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setTitle(int $postId, string $title): bool
    {
        if (function_exists('aioseo') && isset(aioseo()->meta) && method_exists(aioseo()->meta, 'post')) {
            $meta = aioseo()->meta->post->get($postId);
            if ($meta) {
                $meta->title = $title;
                $meta->save();

                return true;
            }
        }

        return (bool) update_post_meta($postId, '_aioseo_title', $title);
    }

    public function getDescription(int $postId): ?string
    {
        if (function_exists('aioseo') && isset(aioseo()->meta) && method_exists(aioseo()->meta, 'post')) {
            $meta = aioseo()->meta->post->get($postId);
            if ($meta && !empty($meta->description)) {
                return (string) $meta->description;
            }
        }

        $value = get_post_meta($postId, '_aioseo_description', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setDescription(int $postId, string $description): bool
    {
        if (function_exists('aioseo') && isset(aioseo()->meta) && method_exists(aioseo()->meta, 'post')) {
            $meta = aioseo()->meta->post->get($postId);
            if ($meta) {
                $meta->description = $description;
                $meta->save();

                return true;
            }
        }

        return (bool) update_post_meta($postId, '_aioseo_description', $description);
    }

    public function getCanonical(int $postId): ?string
    {
        $value = get_post_meta($postId, '_aioseo_canonical_url', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setCanonical(int $postId, string $url): bool
    {
        return (bool) update_post_meta($postId, '_aioseo_canonical_url', $url);
    }

    /**
     * @return array<string, bool>
     */
    public function getRobots(int $postId): array
    {
        $value = get_post_meta($postId, '_aioseo_robots', true);
        if (!is_array($value)) {
            $value = [];
        }

        return [
            'noindex' => !empty($value['noindex']),
            'nofollow' => !empty($value['nofollow']),
        ];
    }

    /**
     * @param array<string, bool> $robots
     */
    public function setRobots(int $postId, array $robots): bool
    {
        return (bool) update_post_meta($postId, '_aioseo_robots', $robots);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchema(int $postId): ?array
    {
        $json = get_post_meta($postId, '_aioseo_schema', true);

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
        return (bool) update_post_meta($postId, '_aioseo_schema', wp_json_encode($schema));
    }

    public function getName(): string
    {
        return 'aioseo';
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getSocialTags(int $postId): array
    {
        return [
            'og' => [
                'title' => $this->readFirstMeta($postId, ['_aioseo_og_title', '_seoauto_og_title']),
                'type' => $this->readFirstMeta($postId, ['_aioseo_og_type', '_seoauto_og_type']),
                'image' => $this->readFirstMeta($postId, ['_aioseo_og_image', '_seoauto_og_image']),
                'url' => $this->readFirstMeta($postId, ['_aioseo_og_url', '_seoauto_og_url']),
                'description' => $this->readFirstMeta($postId, ['_aioseo_og_description', '_seoauto_og_description']),
            ],
            'twitter' => [
                'card' => $this->readFirstMeta($postId, ['_aioseo_twitter_card', '_seoauto_twitter_card']),
                'site' => $this->readFirstMeta($postId, ['_aioseo_twitter_site', '_seoauto_twitter_site']),
                'title' => $this->readFirstMeta($postId, ['_aioseo_twitter_title', '_seoauto_twitter_title']),
                'description' => $this->readFirstMeta($postId, ['_aioseo_twitter_description', '_seoauto_twitter_description']),
                'image' => $this->readFirstMeta($postId, ['_aioseo_twitter_image', '_seoauto_twitter_image']),
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
