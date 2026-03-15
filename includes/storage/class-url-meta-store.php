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
     * Registers all frontend hooks.
     * Call once from Plugin::registerHooks().
     */
    public function registerFrontendHooks(): void
    {
        // Priority 99 so we run AFTER the theme and other plugins, ensuring
        // our stored title always wins for theme-rendered pages.
        add_filter('pre_get_document_title', [$this, 'filterDocumentTitle'], 99);

        // Priority 1 outputs our tags early; priority 0 attempts to remove
        // any theme hooks that would produce duplicate OG/Twitter tags.
        add_action('wp_head', [$this, 'suppressThemeMetaDuplicates'], 0);
        add_action('wp_head', [$this, 'renderMetaTags'], 1);
    }

    /**
     * Attempts to remove theme wp_head hooks that output OG / social meta
     * for URLs where we have stored overrides, preventing duplicate tags.
     *
     * Runs at wp_head priority 0, before any content is output.
     */
    public function suppressThemeMetaDuplicates(): void
    {
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

        $meta          = $data[$hash];
        $hasSocialTags = $this->resolveOgArray($meta) !== []
            || $this->resolveTwitterArray($meta) !== [];

        if (!$hasSocialTags) {
            return;
        }

        global $wp_filter;
        if (!isset($wp_filter['wp_head'])) {
            return;
        }

        $themeDir  = get_stylesheet_directory();
        $parentDir = get_template_directory();

        foreach ($wp_filter['wp_head']->callbacks as $priority => $callbacks) {
            if ($priority <= 1) {
                continue; // Don't remove ourselves.
            }

            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                $file     = $this->resolveCallbackFile($function);

                if ($file === null) {
                    continue;
                }

                // Only target hooks defined in the active theme.
                if (
                    strpos($file, $themeDir) !== 0
                    && strpos($file, $parentDir) !== 0
                ) {
                    continue;
                }

                $name = $this->resolveCallbackName($function);
                if (
                    $name !== null
                    && preg_match('/og|open.?graph|social|meta.?tag|twitter|opengraph/i', $name)
                ) {
                    remove_action('wp_head', $function, $priority);
                }
            }
        }
    }

    /**
     * Filters the document <title> for theme-rendered pages.
     * Runs at priority 99 to override the theme's own title.
     */
    public function filterDocumentTitle(string $title): string
    {
        if (is_singular() && get_the_ID() > 0) {
            return $title;
        }

        $currentUrl = $this->resolveCurrentUrl();
        if ($currentUrl === '') {
            return $title;
        }

        $hash   = md5(rtrim($currentUrl, '/'));
        $data   = $this->getAll();
        $stored = isset($data[$hash]['title']) ? (string) $data[$hash]['title'] : '';

        return $stored !== '' ? $stored : $title;
    }

    /**
     * Outputs non-title <head> meta tags for the current URL when stored
     * overrides exist. Runs at wp_head priority 1.
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

        // ── Open Graph ───────────────────────────────────────────────────────
        $og = $this->resolveOgArray($meta);
        foreach ([
            'title'       => 'og:title',
            'type'        => 'og:type',
            'image'       => 'og:image',
            'url'         => 'og:url',
            'description' => 'og:description',
            'locale'      => 'og:locale',
            'site_name'   => 'og:site_name',
        ] as $key => $property) {
            $value = isset($og[$key]) ? (string) $og[$key] : '';
            if ($value !== '') {
                echo '<meta property="' . esc_attr($property) . '" content="' . esc_attr($value) . '">' . "\n";
            }
        }

        // ── Twitter ──────────────────────────────────────────────────────────
        $twitter = $this->resolveTwitterArray($meta);
        foreach ([
            'card'        => 'twitter:card',
            'site'        => 'twitter:site',
            'title'       => 'twitter:title',
            'description' => 'twitter:description',
            'image'       => 'twitter:image',
        ] as $key => $name) {
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

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function resolveOgArray(array $meta): array
    {
        if (isset($meta['og']) && is_array($meta['og']) && $meta['og'] !== []) {
            return $meta['og'];
        }
        if (
            isset($meta['social_tags']['og'])
            && is_array($meta['social_tags']['og'])
            && $meta['social_tags']['og'] !== []
        ) {
            return $meta['social_tags']['og'];
        }
        return [];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function resolveTwitterArray(array $meta): array
    {
        if (isset($meta['twitter']) && is_array($meta['twitter']) && $meta['twitter'] !== []) {
            return $meta['twitter'];
        }
        if (
            isset($meta['social_tags']['twitter'])
            && is_array($meta['social_tags']['twitter'])
            && $meta['social_tags']['twitter'] !== []
        ) {
            return $meta['social_tags']['twitter'];
        }
        return [];
    }

    /**
     * @param  callable|array<mixed>|string  $function
     */
    private function resolveCallbackFile(mixed $function): ?string
    {
        try {
            if (is_array($function) && count($function) === 2) {
                $ref = new \ReflectionMethod($function[0], (string) $function[1]);
            } elseif (is_string($function) && str_contains($function, '::')) {
                [$class, $method] = explode('::', $function, 2);
                $ref = new \ReflectionMethod($class, $method);
            } elseif ($function instanceof \Closure || is_string($function)) {
                $ref = new \ReflectionFunction($function); // @phpstan-ignore-line
            } else {
                return null;
            }
            return $ref->getFileName() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  callable|array<mixed>|string  $function
     */
    private function resolveCallbackName(mixed $function): ?string
    {
        if (is_array($function) && isset($function[1]) && is_string($function[1])) {
            return $function[1];
        }
        if (is_string($function)) {
            return $function;
        }
        if ($function instanceof \Closure) {
            return '{closure}';
        }
        return null;
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
