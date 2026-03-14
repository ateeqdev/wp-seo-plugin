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
     * Registers all frontend hooks needed to surface stored meta values.
     *
     * Call this once from Plugin::registerHooks() instead of calling
     * renderMetaTags() directly from a wp_head closure.
     */
    public function registerFrontendHooks(): void
    {
        // Title: filter the document title instead of echoing a raw <title> tag.
        // This prevents the double-title problem where WordPress and our hook
        // both output <title> independently.
        add_filter('pre_get_document_title', [$this, 'filterDocumentTitle'], 10);

        // All other head tags (description, canonical, OG, Twitter, robots,
        // schema) are emitted via wp_head at priority 1 so they land early.
        add_action('wp_head', [$this, 'renderMetaTags'], 1);
    }

    /**
     * Filters the document <title> for theme-rendered pages that have a stored
     * title override. Returns '' (empty) to let WordPress fall through to its
     * own title logic when no override exists.
     */
    public function filterDocumentTitle(string $title): string
    {
        if (is_singular() && get_the_ID() > 0) {
            return $title; // Real post — let the SEO plugin handle it.
        }

        $currentUrl = $this->resolveCurrentUrl();
        if ($currentUrl === '') {
            return $title;
        }

        $hash    = md5(rtrim($currentUrl, '/'));
        $data    = $this->getAll();
        $stored  = isset($data[$hash]['title']) ? (string) $data[$hash]['title'] : '';

        return $stored !== '' ? $stored : $title;
    }

    /**
     * Outputs non-title <head> meta tags for the current URL when stored
     * overrides exist.
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

        // ── Meta description ─────────────────────────────────────────────────
        if (!empty($meta['meta_description'])) {
            echo '<meta name="description" content="' . esc_attr((string) $meta['meta_description']) . '">' . "\n";
        }

        // ── Canonical ────────────────────────────────────────────────────────
        if (!empty($meta['canonical'])) {
            echo '<link rel="canonical" href="' . esc_url((string) $meta['canonical']) . '">' . "\n";
        }

        // ── Robots ───────────────────────────────────────────────────────────
        $robotsMeta = $meta['robots'] ?? null;
        if (is_array($robotsMeta)) {
            $directives = [];
            if (!empty($robotsMeta['noindex'])) {
                $directives[] = 'noindex';
            }
            if (!empty($robotsMeta['nofollow'])) {
                $directives[] = 'nofollow';
            }
            if (!empty($directives)) {
                echo '<meta name="robots" content="' . esc_attr(implode(',', $directives)) . '">' . "\n";
            }
        } elseif (is_string($robotsMeta) && $robotsMeta !== '') {
            // Stored as a plain string (e.g. "noindex,nofollow")
            echo '<meta name="robots" content="' . esc_attr($robotsMeta) . '">' . "\n";
        }

        // ── Open Graph tags ──────────────────────────────────────────────────
        $og = isset($meta['og']) && is_array($meta['og']) ? $meta['og'] : [];

        // Also support the social_tags sub-key written by SocialTagsHandler
        // when it goes through the url_meta_store path.
        if ($og === [] && isset($meta['social_tags']['og']) && is_array($meta['social_tags']['og'])) {
            $og = $meta['social_tags']['og'];
        }

        $ogFields = [
            'title'       => 'og:title',
            'type'        => 'og:type',
            'image'       => 'og:image',
            'url'         => 'og:url',
            'description' => 'og:description',
            'locale'      => 'og:locale',
            'site_name'   => 'og:site_name',
        ];
        foreach ($ogFields as $key => $property) {
            $value = isset($og[$key]) ? (string) $og[$key] : '';
            if ($value !== '') {
                echo '<meta property="' . esc_attr($property) . '" content="' . esc_attr($value) . '">' . "\n";
            }
        }

        // ── Twitter card tags ────────────────────────────────────────────────
        $twitter = isset($meta['twitter']) && is_array($meta['twitter']) ? $meta['twitter'] : [];

        if ($twitter === [] && isset($meta['social_tags']['twitter']) && is_array($meta['social_tags']['twitter'])) {
            $twitter = $meta['social_tags']['twitter'];
        }

        $twitterFields = [
            'card'        => 'twitter:card',
            'site'        => 'twitter:site',
            'title'       => 'twitter:title',
            'description' => 'twitter:description',
            'image'       => 'twitter:image',
        ];
        foreach ($twitterFields as $key => $name) {
            $value = isset($twitter[$key]) ? (string) $twitter[$key] : '';
            if ($value !== '') {
                echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($value) . '">' . "\n";
            }
        }

        // ── JSON-LD schema ───────────────────────────────────────────────────
        if (!empty($meta['schema_json_ld'])) {
            echo '<script type="application/ld+json">';
            echo wp_json_encode($meta['schema_json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "</script>\n";
        }
    }

    /**
     * Resolves the canonical URL for the current WordPress request.
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
