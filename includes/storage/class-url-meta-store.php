<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Storage;

final class UrlMetaStore
{
    private const OPTION_KEY = 'seoworkerai_url_meta';

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAll(): array
    {
        $data = get_option(self::OPTION_KEY, []);
        return is_array($data) ? $data : [];
    }

    private function saveAll(array $data): void
    {
        update_option(self::OPTION_KEY, $data, false);
    }

    public function getMeta(string $url, string $key)
    {
        $data = $this->getAll();
        $hash = md5(rtrim($url, '/'));

        return $data[$hash][$key] ?? null;
    }

    public function setMeta(string $url, string $key, $value): bool
    {
        $data = $this->getAll();
        $hash = md5(rtrim($url, '/'));

        if (!isset($data[$hash])) {
            $data[$hash] = ['url' => rtrim($url, '/')];
        }

        $data[$hash][$key] = $value;
        $this->saveAll($data);
        return true;
    }

    public function deleteMeta(string $url, string $key): bool
    {
        $data = $this->getAll();
        $hash = md5(rtrim($url, '/'));

        if (isset($data[$hash][$key])) {
            unset($data[$hash][$key]);
            $this->saveAll($data);
        }
        return true;
    }

    /**
     * Outputs <head> meta tags for the current URL when stored overrides exist.
     *
     * Only runs for non-singular pages (theme-rendered URLs). For singular posts
     * the active SEO plugin (Yoast / RankMath / etc.) handles tag output and we
     * should not duplicate.
     *
     * For the homepage WordPress may resolve it as singular (the "static front
     * page" setting). We special-case it: if is_front_page() is true but
     * is_singular() is false (pure theme homepage), we still render our tags.
     * If it's a static page assigned as the front page, url_to_postid() will
     * have returned a real post_id, so actions were already applied via the post
     * path and the SEO plugin renders the output.
     */
    public function renderMetaTags(): void
    {
        // Always skip when the current page is backed by a real post_id that
        // the SEO plugin will handle.
        if (is_singular() && get_the_ID() > 0) {
            return;
        }

        $currentUrl = $this->resolveCurrentUrl();
        if ($currentUrl === '') {
            return;
        }

        $hash = md5(rtrim($currentUrl, '/'));
        $data = $this->getAll();

        if (!isset($data[$hash])) {
            return;
        }

        $meta = $data[$hash];

        if (!empty($meta['title'])) {
            echo "<title>" . esc_html($meta['title']) . "</title>\n";
        }
        if (!empty($meta['meta_description'])) {
            echo '<meta name="description" content="' . esc_attr($meta['meta_description']) . '">' . "\n";
        }
        if (!empty($meta['canonical'])) {
            echo '<link rel="canonical" href="' . esc_url($meta['canonical']) . '">' . "\n";
        }

        $directives = [];
        if (!empty($meta['robots']['noindex'])) {
            $directives[] = 'noindex';
        }
        if (!empty($meta['robots']['nofollow'])) {
            $directives[] = 'nofollow';
        }
        if (!empty($directives)) {
            echo '<meta name="robots" content="' . esc_attr(implode(',', $directives)) . '">' . "\n";
        }

        if (!empty($meta['schema_json_ld'])) {
            echo "<script type=\"application/ld+json\">";
            echo wp_json_encode($meta['schema_json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "</script>\n";
        }
    }

    /**
     * Resolves the canonical URL for the current WordPress request.
     *
     * Handles the following cases:
     *  - Static front page (is_front_page() + is_singular()) → backed by post, skip.
     *  - Pure theme homepage (is_front_page(), not singular) → home_url('/').
     *  - Archives, category/tag/taxonomy pages, author pages, date archives.
     *  - Search results, 404, and any other template-rendered URL.
     */
    private function resolveCurrentUrl(): string
    {
        // Front page that is a static WordPress page — handled by SEO plugin.
        if (is_front_page() && is_singular()) {
            return '';
        }

        // Pure theme homepage or "posts page" set as front page.
        if (is_front_page() || is_home()) {
            return home_url('/');
        }

        global $wp;
        return home_url(add_query_arg([], $wp->request));
    }
}
