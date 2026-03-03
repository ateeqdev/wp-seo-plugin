<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\SEO;

final class RankmathAdapter implements InterfaceSeoAdapter
{
    public function getTitle(int $postId): ?string
    {
        $value = get_post_meta($postId, '_rank_math_title', true);

        if ($value === '') {
            $value = get_post_meta($postId, 'rank_math_title', true);
        }

        return $value !== '' ? (string) $value : null;
    }

    public function setTitle(int $postId, string $title): bool
    {
        $a = (bool) update_post_meta($postId, 'rank_math_title', $title);
        $b = (bool) update_post_meta($postId, '_rank_math_title', $title);

        return $a || $b;
    }

    public function getDescription(int $postId): ?string
    {
        $value = get_post_meta($postId, '_rank_math_description', true);

        if ($value === '') {
            $value = get_post_meta($postId, 'rank_math_description', true);
        }

        return $value !== '' ? (string) $value : null;
    }

    public function setDescription(int $postId, string $description): bool
    {
        $a = (bool) update_post_meta($postId, 'rank_math_description', $description);
        $b = (bool) update_post_meta($postId, '_rank_math_description', $description);

        return $a || $b;
    }

    public function getCanonical(int $postId): ?string
    {
        $value = get_post_meta($postId, '_rank_math_canonical_url', true);

        if ($value === '') {
            $value = get_post_meta($postId, 'rank_math_canonical_url', true);
        }

        return $value !== '' ? (string) $value : null;
    }

    public function setCanonical(int $postId, string $url): bool
    {
        $a = (bool) update_post_meta($postId, 'rank_math_canonical_url', $url);
        $b = (bool) update_post_meta($postId, '_rank_math_canonical_url', $url);

        return $a || $b;
    }

    /**
     * @return array<string, bool>
     */
    public function getRobots(int $postId): array
    {
        $value = get_post_meta($postId, '_rank_math_robots', true);
        if (!is_string($value) || $value === '') {
            $value = (string) get_post_meta($postId, 'rank_math_robots', true);
        }

        $parts = array_filter(array_map('trim', explode(',', $value)));

        return [
            'noindex' => in_array('noindex', $parts, true),
            'nofollow' => in_array('nofollow', $parts, true),
        ];
    }

    /**
     * @param array<string, bool> $robots
     */
    public function setRobots(int $postId, array $robots): bool
    {
        $parts = [];

        if (!empty($robots['noindex'])) {
            $parts[] = 'noindex';
        }

        if (!empty($robots['nofollow'])) {
            $parts[] = 'nofollow';
        }

        $value = implode(',', $parts);

        $a = (bool) update_post_meta($postId, 'rank_math_robots', $value);
        $b = (bool) update_post_meta($postId, '_rank_math_robots', $value);

        return $a || $b;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchema(int $postId): ?array
    {
        $json = get_post_meta($postId, '_seoauto_schema_json_ld', true);

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
        return (bool) update_post_meta($postId, '_seoauto_schema_json_ld', wp_json_encode($schema));
    }

    public function getName(): string
    {
        return 'rankmath';
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getSocialTags(int $postId): array
    {
        return [
            'og' => [
                'title' => $this->readFirstMeta($postId, ['rank_math_facebook_title', '_rank_math_facebook_title', '_seoauto_og_title']),
                'type' => $this->readFirstMeta($postId, ['rank_math_facebook_type', '_rank_math_facebook_type', '_seoauto_og_type']),
                'image' => $this->readFirstMeta($postId, ['rank_math_facebook_image', '_rank_math_facebook_image', '_seoauto_og_image']),
                'url' => $this->readFirstMeta($postId, ['rank_math_facebook_url', '_rank_math_facebook_url', '_seoauto_og_url']),
                'description' => $this->readFirstMeta($postId, ['rank_math_facebook_description', '_rank_math_facebook_description', '_seoauto_og_description']),
            ],
            'twitter' => [
                'card' => $this->readFirstMeta($postId, ['rank_math_twitter_card_type', '_rank_math_twitter_card_type', '_seoauto_twitter_card']),
                'site' => $this->readFirstMeta($postId, ['rank_math_twitter_site', '_rank_math_twitter_site', '_seoauto_twitter_site']),
                'title' => $this->readFirstMeta($postId, ['rank_math_twitter_title', '_rank_math_twitter_title', '_seoauto_twitter_title']),
                'description' => $this->readFirstMeta($postId, ['rank_math_twitter_description', '_rank_math_twitter_description', '_seoauto_twitter_description']),
                'image' => $this->readFirstMeta($postId, ['rank_math_twitter_image', '_rank_math_twitter_image', '_seoauto_twitter_image']),
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
