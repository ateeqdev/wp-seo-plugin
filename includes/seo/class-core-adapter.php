<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\SEO;

final class CoreAdapter implements InterfaceSeoAdapter
{
    private const PREFIX = '_seoworkerai_';

    public function getTitle(int $postId): ?string
    {
        $value = get_post_meta($postId, self::PREFIX . 'title', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setTitle(int $postId, string $title): bool
    {
        return (bool) update_post_meta($postId, self::PREFIX . 'title', $title);
    }

    public function getDescription(int $postId): ?string
    {
        $value = get_post_meta($postId, self::PREFIX . 'meta_description', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setDescription(int $postId, string $description): bool
    {
        return (bool) update_post_meta($postId, self::PREFIX . 'meta_description', $description);
    }

    public function getCanonical(int $postId): ?string
    {
        $value = get_post_meta($postId, self::PREFIX . 'canonical', true);

        return $value !== '' ? (string) $value : null;
    }

    public function setCanonical(int $postId, string $url): bool
    {
        return (bool) update_post_meta($postId, self::PREFIX . 'canonical', $url);
    }

    /**
     * @return array<string, bool>
     */
    public function getRobots(int $postId): array
    {
        $value = get_post_meta($postId, self::PREFIX . 'robots', true);

        if (is_array($value)) {
            return [
                'noindex' => ! empty($value['noindex']),
                'nofollow' => ! empty($value['nofollow']),
            ];
        }

        return ['noindex' => false, 'nofollow' => false];
    }

    /**
     * @param  array<string, bool>  $robots
     */
    public function setRobots(int $postId, array $robots): bool
    {
        return (bool) update_post_meta(
            $postId,
            self::PREFIX . 'robots',
            [
                'noindex' => ! empty($robots['noindex']),
                'nofollow' => ! empty($robots['nofollow']),
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchema(int $postId): ?array
    {
        $json = get_post_meta($postId, self::PREFIX . 'schema_json_ld', true);

        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function setSchema(int $postId, array $schema): bool
    {
        return (bool) update_post_meta($postId, self::PREFIX . 'schema_json_ld', wp_json_encode($schema));
    }

    public function getName(): string
    {
        return 'core';
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getSocialTags(int $postId): array
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
     * Registers wp_head and title hooks for singular post pages.
     * Called from Plugin::registerHooks() after plugin services are booted.
     */
    public function registerFrontendHooks(): void
    {
        // Use pre_get_document_title filter instead of echoing a raw <title>
        // tag — prevents the double-title problem where WordPress and our hook
        // both output <title> independently.
        add_filter('pre_get_document_title', [$this, 'filterDocumentTitle'], 10);
        add_action('wp_head', [$this, 'renderMetaTags'], 1);
    }

    /**
     * Filters the document title for singular posts that have a stored title.
     */
    public function filterDocumentTitle(string $title): string
    {
        if (! is_singular()) {
            return $title;
        }

        $postId = get_the_ID();
        if (! is_int($postId) || $postId <= 0) {
            return $title;
        }

        $stored = $this->getTitle($postId);

        return $stored !== null && $stored !== '' ? $stored : $title;
    }

    /**
     * Outputs non-title <head> meta tags for singular posts.
     * Title is handled by filterDocumentTitle() via pre_get_document_title.
     */
    public function renderMetaTags(): void
    {
        if (! is_singular()) {
            return;
        }

        $postId = get_the_ID();
        if (! is_int($postId) || $postId <= 0) {
            return;
        }

        $description = $this->getDescription($postId);
        if ($description !== null) {
            echo '<meta name="description" content="'.esc_attr($description).'">'."\n";
        }

        $canonical = $this->getCanonical($postId);
        if ($canonical !== null) {
            echo '<link rel="canonical" href="'.esc_url($canonical).'">'."\n";
        }

        $robots = $this->getRobots($postId);
        $directives = [];
        if (! empty($robots['noindex'])) {
            $directives[] = 'noindex';
        }
        if (! empty($robots['nofollow'])) {
            $directives[] = 'nofollow';
        }
        if (! empty($directives)) {
            echo '<meta name="robots" content="'.esc_attr(implode(',', $directives)).'">'."\n";
        }

        $schema = $this->getSchema($postId);
        if ($schema !== null) {
            echo '<script type="application/ld+json">';
            echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "</script>\n";
        }

        $title = $this->getTitle($postId);
        $socialMap = [
            'og:title' => (string) get_post_meta($postId, '_seoworkerai_og_title', true),
            'og:type' => (string) get_post_meta($postId, '_seoworkerai_og_type', true),
            'og:image' => (string) get_post_meta($postId, '_seoworkerai_og_image', true),
            'og:url' => (string) get_post_meta($postId, '_seoworkerai_og_url', true),
            'og:description' => (string) get_post_meta($postId, '_seoworkerai_og_description', true),
            'twitter:card' => (string) get_post_meta($postId, '_seoworkerai_twitter_card', true),
            'twitter:site' => (string) get_post_meta($postId, '_seoworkerai_twitter_site', true),
            'twitter:title' => (string) get_post_meta($postId, '_seoworkerai_twitter_title', true),
            'twitter:description' => (string) get_post_meta($postId, '_seoworkerai_twitter_description', true),
            'twitter:image' => (string) get_post_meta($postId, '_seoworkerai_twitter_image', true),
        ];

        // Fallback: use stored title/description for OG if no dedicated OG value.
        if ($title !== null && $socialMap['og:title'] === '') {
            $socialMap['og:title'] = $title;
        }

        if ($description !== null && $socialMap['og:description'] === '') {
            $socialMap['og:description'] = $description;
        }

        foreach ($socialMap as $name => $value) {
            if ($value === '') {
                continue;
            }

            if (str_starts_with($name, 'og:')) {
                echo '<meta property="'.esc_attr($name).'" content="'.esc_attr($value).'">'."\n";
            } else {
                echo '<meta name="'.esc_attr($name).'" content="'.esc_attr($value).'">'."\n";
            }
        }
    }
}
