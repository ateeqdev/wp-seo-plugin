<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\SEO;

final class YoastAdapter extends AbstractMetaBackedAdapter
{
    public function getTitle(int $postId): ?string
    {
        return $this->readOptionalMeta($postId, ['_yoast_wpseo_title']);
    }

    public function setTitle(int $postId, string $title): bool
    {
        return $this->writeMeta($postId, ['_yoast_wpseo_title'], $title);
    }

    public function getDescription(int $postId): ?string
    {
        return $this->readOptionalMeta($postId, ['_yoast_wpseo_metadesc']);
    }

    public function setDescription(int $postId, string $description): bool
    {
        return $this->writeMeta($postId, ['_yoast_wpseo_metadesc'], $description);
    }

    public function getCanonical(int $postId): ?string
    {
        return $this->readOptionalMeta($postId, ['_yoast_wpseo_canonical']);
    }

    public function setCanonical(int $postId, string $url): bool
    {
        return $this->writeMeta($postId, ['_yoast_wpseo_canonical'], $url);
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
        return $this->readJsonMeta($postId, '_yoast_wpseo_schema');
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function setSchema(int $postId, array $schema): bool
    {
        return $this->writeJsonMeta($postId, '_yoast_wpseo_schema', $schema);
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
        return $this->buildSocialTags($postId, [
            'og' => [
                'title' => ['_yoast_wpseo_opengraph-title', '_seoworkerai_og_title'],
                'type' => ['_yoast_wpseo_opengraph-type', '_seoworkerai_og_type'],
                'image' => ['_yoast_wpseo_opengraph-image', '_seoworkerai_og_image'],
                'url' => ['_yoast_wpseo_opengraph-url', '_seoworkerai_og_url'],
                'description' => ['_yoast_wpseo_opengraph-description', '_seoworkerai_og_description'],
            ],
            'twitter' => [
                'card' => ['_yoast_wpseo_twitter-card', '_seoworkerai_twitter_card'],
                'site' => ['_yoast_wpseo_twitter-site', '_seoworkerai_twitter_site'],
                'title' => ['_yoast_wpseo_twitter-title', '_seoworkerai_twitter_title'],
                'description' => ['_yoast_wpseo_twitter-description', '_seoworkerai_twitter_description'],
                'image' => ['_yoast_wpseo_twitter-image', '_seoworkerai_twitter_image'],
            ],
        ]);
    }
}
