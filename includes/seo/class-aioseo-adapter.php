<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\SEO;

final class AioseoAdapter extends AbstractMetaBackedAdapter
{
    public function getTitle(int $postId): ?string
    {
        if (function_exists('aioseo') && isset(aioseo()->meta) && method_exists(aioseo()->meta, 'post')) {
            $meta = aioseo()->meta->post->get($postId);
            if ($meta && !empty($meta->title)) {
                return (string) $meta->title;
            }
        }

        return $this->readOptionalMeta($postId, ['_aioseo_title']);
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

        return $this->writeMeta($postId, ['_aioseo_title'], $title);
    }

    public function getDescription(int $postId): ?string
    {
        if (function_exists('aioseo') && isset(aioseo()->meta) && method_exists(aioseo()->meta, 'post')) {
            $meta = aioseo()->meta->post->get($postId);
            if ($meta && !empty($meta->description)) {
                return (string) $meta->description;
            }
        }

        return $this->readOptionalMeta($postId, ['_aioseo_description']);
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

        return $this->writeMeta($postId, ['_aioseo_description'], $description);
    }

    public function getCanonical(int $postId): ?string
    {
        return $this->readOptionalMeta($postId, ['_aioseo_canonical_url']);
    }

    public function setCanonical(int $postId, string $url): bool
    {
        return $this->writeMeta($postId, ['_aioseo_canonical_url'], $url);
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
        return $this->readJsonMeta($postId, '_aioseo_schema');
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function setSchema(int $postId, array $schema): bool
    {
        return $this->writeJsonMeta($postId, '_aioseo_schema', $schema);
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
        return $this->buildSocialTags($postId, [
            'og' => [
                'title' => ['_aioseo_og_title', '_seoworkerai_og_title'],
                'type' => ['_aioseo_og_type', '_seoworkerai_og_type'],
                'image' => ['_aioseo_og_image', '_seoworkerai_og_image'],
                'url' => ['_aioseo_og_url', '_seoworkerai_og_url'],
                'description' => ['_aioseo_og_description', '_seoworkerai_og_description'],
            ],
            'twitter' => [
                'card' => ['_aioseo_twitter_card', '_seoworkerai_twitter_card'],
                'site' => ['_aioseo_twitter_site', '_seoworkerai_twitter_site'],
                'title' => ['_aioseo_twitter_title', '_seoworkerai_twitter_title'],
                'description' => ['_aioseo_twitter_description', '_seoworkerai_twitter_description'],
                'image' => ['_aioseo_twitter_image', '_seoworkerai_twitter_image'],
            ],
        ]);
    }
}
