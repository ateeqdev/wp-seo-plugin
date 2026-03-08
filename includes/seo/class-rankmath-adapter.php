<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\SEO;

final class RankmathAdapter extends AbstractMetaBackedAdapter
{
    public function getTitle(int $postId): ?string
    {
        return $this->readOptionalMeta($postId, ['_rank_math_title', 'rank_math_title']);
    }

    public function setTitle(int $postId, string $title): bool
    {
        return $this->writeMeta($postId, ['rank_math_title', '_rank_math_title'], $title);
    }

    public function getDescription(int $postId): ?string
    {
        return $this->readOptionalMeta($postId, ['_rank_math_description', 'rank_math_description']);
    }

    public function setDescription(int $postId, string $description): bool
    {
        return $this->writeMeta($postId, ['rank_math_description', '_rank_math_description'], $description);
    }

    public function getCanonical(int $postId): ?string
    {
        return $this->readOptionalMeta($postId, ['_rank_math_canonical_url', 'rank_math_canonical_url']);
    }

    public function setCanonical(int $postId, string $url): bool
    {
        return $this->writeMeta($postId, ['rank_math_canonical_url', '_rank_math_canonical_url'], $url);
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
        return $this->readJsonMeta($postId, '_seoworkerai_schema_json_ld');
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function setSchema(int $postId, array $schema): bool
    {
        return $this->writeJsonMeta($postId, '_seoworkerai_schema_json_ld', $schema);
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
        return $this->buildSocialTags($postId, [
            'og' => [
                'title' => ['rank_math_facebook_title', '_rank_math_facebook_title', '_seoworkerai_og_title'],
                'type' => ['rank_math_facebook_type', '_rank_math_facebook_type', '_seoworkerai_og_type'],
                'image' => ['rank_math_facebook_image', '_rank_math_facebook_image', '_seoworkerai_og_image'],
                'url' => ['rank_math_facebook_url', '_rank_math_facebook_url', '_seoworkerai_og_url'],
                'description' => ['rank_math_facebook_description', '_rank_math_facebook_description', '_seoworkerai_og_description'],
            ],
            'twitter' => [
                'card' => ['rank_math_twitter_card_type', '_rank_math_twitter_card_type', '_seoworkerai_twitter_card'],
                'site' => ['rank_math_twitter_site', '_rank_math_twitter_site', '_seoworkerai_twitter_site'],
                'title' => ['rank_math_twitter_title', '_rank_math_twitter_title', '_seoworkerai_twitter_title'],
                'description' => ['rank_math_twitter_description', '_rank_math_twitter_description', '_seoworkerai_twitter_description'],
                'image' => ['rank_math_twitter_image', '_rank_math_twitter_image', '_seoworkerai_twitter_image'],
            ],
        ]);
    }
}
