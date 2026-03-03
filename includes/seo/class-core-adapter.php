<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\SEO;

final class CoreAdapter implements InterfaceSeoAdapter
{
    private const PREFIX = '_seoauto_';

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
                'noindex' => !empty($value['noindex']),
                'nofollow' => !empty($value['nofollow']),
            ];
        }

        return [
            'noindex' => false,
            'nofollow' => false,
        ];
    }

    /**
     * @param array<string, bool> $robots
     */
    public function setRobots(int $postId, array $robots): bool
    {
        return (bool) update_post_meta(
            $postId,
            self::PREFIX . 'robots',
            [
                'noindex' => !empty($robots['noindex']),
                'nofollow' => !empty($robots['nofollow']),
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
     * @param array<string, mixed> $schema
     */
    public function setSchema(int $postId, array $schema): bool
    {
        return (bool) update_post_meta($postId, self::PREFIX . 'schema_json_ld', wp_json_encode($schema));
    }

    public function getName(): string
    {
        return 'core';
    }

    public function renderMetaTags(): void
    {
        if (!is_singular()) {
            return;
        }

        $postId = get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return;
        }

        $title = $this->getTitle($postId);
        if ($title !== null) {
            echo "<title>" . esc_html($title) . "</title>\n";
            echo '<meta property="og:title" content="' . esc_attr($title) . '">';
            echo "\n";
        }

        $description = $this->getDescription($postId);
        if ($description !== null) {
            echo '<meta name="description" content="' . esc_attr($description) . '">';
            echo "\n";
            echo '<meta property="og:description" content="' . esc_attr($description) . '">';
            echo "\n";
        }

        $canonical = $this->getCanonical($postId);
        if ($canonical !== null) {
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">';
            echo "\n";
        }

        $robots = $this->getRobots($postId);
        $directives = [];
        if (!empty($robots['noindex'])) {
            $directives[] = 'noindex';
        }
        if (!empty($robots['nofollow'])) {
            $directives[] = 'nofollow';
        }
        if (!empty($directives)) {
            echo '<meta name="robots" content="' . esc_attr(implode(',', $directives)) . '">';
            echo "\n";
        }

        $schema = $this->getSchema($postId);
        if ($schema !== null) {
            echo "<script type=\"application/ld+json\">";
            echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "</script>\n";
        }

        $socialMap = [
            'og:title' => (string) get_post_meta($postId, '_seoauto_og_title', true),
            'og:type' => (string) get_post_meta($postId, '_seoauto_og_type', true),
            'og:image' => (string) get_post_meta($postId, '_seoauto_og_image', true),
            'og:url' => (string) get_post_meta($postId, '_seoauto_og_url', true),
            'og:description' => (string) get_post_meta($postId, '_seoauto_og_description', true),
            'twitter:card' => (string) get_post_meta($postId, '_seoauto_twitter_card', true),
            'twitter:site' => (string) get_post_meta($postId, '_seoauto_twitter_site', true),
            'twitter:title' => (string) get_post_meta($postId, '_seoauto_twitter_title', true),
            'twitter:description' => (string) get_post_meta($postId, '_seoauto_twitter_description', true),
            'twitter:image' => (string) get_post_meta($postId, '_seoauto_twitter_image', true),
        ];

        foreach ($socialMap as $name => $value) {
            if ($value === '') {
                continue;
            }

            $isProperty = str_starts_with($name, 'og:');
            if ($isProperty) {
                echo '<meta property="' . esc_attr($name) . '" content="' . esc_attr($value) . '">' . "\n";
                continue;
            }

            echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($value) . '">' . "\n";
        }
    }
}
