<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\REST;

use SEOWorkerAI\Connector\Auth\SiteTokenManager;
use SEOWorkerAI\Connector\SEO\SeoDetector;
use SEOWorkerAI\Connector\Storage\UrlMetaStore;

final class PagesEndpoint
{
    private SiteTokenManager $tokenManager;

    private SeoDetector $seoDetector;

    public function __construct(SiteTokenManager $tokenManager, SeoDetector $seoDetector)
    {
        $this->tokenManager = $tokenManager;
        $this->seoDetector = $seoDetector;
    }

    public function registerRoutes(): void
    {

        $namespace = 'seoworkerai/v1';
        register_rest_route($namespace, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'listPages'],
            'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route($namespace, '/pages/by-url', [
            'methods' => 'GET',
            'callback' => [$this, 'getPageByUrl'],
            'permission_callback' => [$this, 'authorize'],
        ]);
        register_rest_route($namespace, '/pages/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getPage'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(\WP_REST_Request $request)
    {
        $token = (string) $request->get_header('X-Site-Token');
        if (! $this->tokenManager->verifyInboundToken($token)) {
            return new \WP_Error('seoworkerai_unauthorized', 'Invalid site token.', ['status' => 401]);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Route handlers
    // -------------------------------------------------------------------------

    /** @return \WP_REST_Response|\WP_Error */
    public function listPages(\WP_REST_Request $request)
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = min(200, max(1, (int) $request->get_param('per_page')));
        $postType = (string) ($request->get_param('post_type') ?? 'any');
        $modifiedAfter = (string) ($request->get_param('modified_after') ?? '');

        $allowedTypes = ['any', 'post', 'page', 'product'];
        if (! in_array($postType, $allowedTypes, true)) {
            $postType = 'any';
        }

        $queryArgs = [
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if ($modifiedAfter !== '') {
            $queryArgs['date_query'] = [[
                'column' => 'post_modified_gmt',
                'after' => $modifiedAfter,
            ]];
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        // On the first page, always prepend the homepage so callers always get
        // real content regardless of whether the front page is post-backed or
        // theme-rendered.
        if ($page === 1 && in_array($postType, ['any', 'page'], true) && $modifiedAfter === '') {
            $homeUrl = home_url('/');
            $homeRequest = new \WP_REST_Request('GET', '/pages/by-url');
            $homeRequest->set_param('url', $homeUrl);
            $homeResponse = $this->getPageByUrl($homeRequest);

            if (! is_wp_error($homeResponse)) {
                $homeData = $homeResponse->get_data();
                if (isset($homeData['data']) && is_array($homeData['data'])) {
                    $items[] = $homeData['data'];
                }
            }
        }

        foreach ($query->posts as $post) {
            if ($post instanceof \WP_Post) {
                $items[] = $this->formatPost($post);
            }
        }

        return new \WP_REST_Response([
            'status' => 'ok',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'has_more' => $query->max_num_pages > $page,
                ],
            ],
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function getPage(\WP_REST_Request $request)
    {
        $postId = (int) $request->get_param('id');
        $post = get_post($postId);

        if (! $post instanceof \WP_Post || $post->post_status !== 'publish') {
            return new \WP_Error('seoworkerai_not_found', 'Post not found.', ['status' => 404]);
        }

        return new \WP_REST_Response([
            'status' => 'ok',
            'data' => $this->formatPost($post),
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function getPageByUrl(\WP_REST_Request $request)
    {
        $url = (string) $request->get_param('url');
        if ($url === '') {
            return new \WP_Error('seoworkerai_invalid_url', 'Missing URL parameter.', ['status' => 400]);
        }

        $postId = url_to_postid($url);

        if ($postId > 0) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post && $post->post_status === 'publish') {
                return new \WP_REST_Response([
                    'status' => 'ok',
                    'data' => $this->formatPost($post),
                ]);
            }
        }

        // Theme-rendered URL — build a synthetic payload with real rendered content.
        return new \WP_REST_Response([
            'status' => 'ok',
            'data' => $this->formatThemeRenderedUrl($url),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private formatters
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function formatPost(\WP_Post $post): array
    {
        $adapter = $this->seoDetector->getAdapter();

        return [
            'id' => $post->ID,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'url' => get_permalink($post->ID),
            'slug' => $post->post_name,
            'title' => get_the_title($post->ID),
            'excerpt' => get_the_excerpt($post->ID),
            'content_html' => apply_filters('the_content', $post->post_content),
            'content_text' => wp_strip_all_tags($post->post_content),
            'seo' => [
                'title' => $adapter->getTitle($post->ID),
                'meta_description' => $adapter->getDescription($post->ID),
                'canonical' => $adapter->getCanonical($post->ID),
                'robots' => $adapter->getRobots($post->ID),
                'schema_markup' => $adapter->getSchema($post->ID),
                'social_tags' => $adapter->getSocialTags($post->ID),
            ],
            'published_at' => gmdate('c', strtotime((string) $post->post_date_gmt)),
            'modified_gmt' => gmdate('c', strtotime((string) $post->post_modified_gmt)),
        ];
    }

    /**
     * Builds a synthetic page payload for a URL rendered purely by the theme.
     *
     * Content is obtained via a loopback HTTP request to the URL itself so that
     * WordPress performs a full frontend render — the only reliable way to get
     * the rendered HTML of a theme-driven page (homepage, archive, etc.) from
     * inside a REST callback.
     *
     * SEO values are resolved by merging UrlMetaStore overrides with SEO adapter
     * globals (post_id = 0, i.e. site-wide defaults).
     *
     * @return array<string, mixed>
     */
    private function formatThemeRenderedUrl(string $url): array
    {
        $adapter = $this->seoDetector->getAdapter();
        $urlStore = new UrlMetaStore;

        // Stored overrides take precedence over adapter globals.
        $storedTitle = (string) ($urlStore->getMeta($url, 'title') ?? '');
        $storedDescription = (string) ($urlStore->getMeta($url, 'meta_description') ?? '');
        $storedCanonical = (string) ($urlStore->getMeta($url, 'canonical') ?? '');
        $storedRobots = $urlStore->getMeta($url, 'robots');
        $storedSchema = $urlStore->getMeta($url, 'schema_json_ld');

        $effectiveTitle = $storedTitle !== '' ? $storedTitle : (string) ($adapter->getTitle(0) ?? '');
        $effectiveDescription = $storedDescription !== '' ? $storedDescription : (string) ($adapter->getDescription(0) ?? '');
        $effectiveCanonical = $storedCanonical !== '' ? $storedCanonical : (string) ($adapter->getCanonical(0) ?? '');
        $effectiveRobots = is_array($storedRobots) ? $storedRobots : $adapter->getRobots(0);
        $effectiveSchema = is_array($storedSchema) ? $storedSchema : $adapter->getSchema(0);

        $displayTitle = $effectiveTitle !== '' ? $effectiveTitle : (string) get_bloginfo('name');

        // Render the page via loopback and extract body content.
        [$contentHtml, $contentText] = $this->fetchRenderedContent($url);

        return [
            'id' => null,
            'post_type' => 'page',
            'status' => 'publish',
            'url' => $url,
            'slug' => '',
            'title' => $displayTitle,
            'excerpt' => '',
            'content_html' => $contentHtml,
            'content_text' => $contentText,
            'seo' => [
                'title' => $effectiveTitle,
                'meta_description' => $effectiveDescription,
                'canonical' => $effectiveCanonical,
                'robots' => $effectiveRobots,
                'schema_markup' => $effectiveSchema,
                'social_tags' => $adapter->getSocialTags(0),
            ],
            'published_at' => gmdate('c'),
            'modified_gmt' => gmdate('c'),
        ];
    }

    // -------------------------------------------------------------------------
    // Loopback renderer
    // -------------------------------------------------------------------------

    /**
     * Fetches the fully rendered HTML of a URL via a loopback HTTP request and
     * extracts the main body content.
     *
     * Strategy (in order):
     *   1. Use wp_remote_get() with a short timeout — safe on any host.
     *   2. Strip <head>, <script>, <style>, <nav>, <header>, <footer> tags so
     *      the caller receives only meaningful page body text.
     *   3. On failure (loopback blocked, timeout, etc.) return empty strings
     *      so the rest of the payload is still usable.
     *
     * @return array{0: string, 1: string} [content_html, content_text]
     */
    private function fetchRenderedContent(string $url): array
    {
        $empty = ['', ''];

        if (! $this->loopbackAllowed()) {
            return $empty;
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'SEOWorkerAI-Loopback/1.0 (content-extraction)',
            // Prevent infinite loopback auth loops: skip cookie jar entirely.
            'cookies' => [],
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        if (is_wp_error($response)) {
            return $empty;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 400) {
            return $empty;
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return $empty;
        }

        $contentHtml = $this->extractBodyContent($html);
        $contentText = $contentHtml !== '' ? wp_strip_all_tags($contentHtml) : '';

        // Normalise whitespace in the text version.
        $contentText = (string) preg_replace('/\s+/', ' ', $contentText);
        $contentText = trim($contentText);

        return [$contentHtml, $contentText];
    }

    /**
     * Checks whether a loopback request is likely to succeed on this install.
     *
     * Skips the request when:
     *   - wp_http_supports_ssl() or basic HTTP stack is unavailable (rare).
     *   - WordPress's own loopback health check has previously marked loopbacks
     *     as unavailable (stored in the site health transient).
     */
    private function loopbackAllowed(): bool
    {
        // Respect WP Site Health loopback status if already tested.
        $loopbackResult = get_transient('health-check-loopback-requests');
        if ($loopbackResult === 'failed') {
            return false;
        }

        // Allow plugins / hosting environments to opt out.
        return (bool) apply_filters('seoworkerai_loopback_enabled', true);
    }

    /**
     * Extracts the meaningful body content from a full HTML page string.
     *
     * Removes: <head>, <script>, <style>, <noscript>, <nav>, <header>,
     * <footer>, <aside>, and HTML comments, then isolates the <body> inner HTML.
     * Falls back to the full stripped HTML if no <body> tag is found.
     *
     * @return string Clean HTML containing only visible body content.
     */
    private function extractBodyContent(string $html): string
    {
        // Use internal errors so libxml noise doesn't surface to the caller.
        $previousErrors = libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        // The @ suppresses residual parse warnings even with internal errors on.
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        // Tags whose entire subtree should be removed before extracting content.
        $removeTags = ['head', 'script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside'];

        foreach ($removeTags as $tagName) {
            $nodes = iterator_to_array($doc->getElementsByTagName($tagName));
            foreach ($nodes as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // Remove HTML comments.
        $xpath = new \DOMXPath($doc);
        $comments = iterator_to_array($xpath->query('//comment()') ?: new \DOMNodeList);
        foreach ($comments as $comment) {
            if ($comment->parentNode) {
                $comment->parentNode->removeChild($comment);
            }
        }

        // Extract <body> inner HTML if present.
        $bodyNodes = $doc->getElementsByTagName('body');
        if ($bodyNodes->length > 0) {
            $body = $bodyNodes->item(0);
            $inner = '';
            foreach ($body->childNodes as $child) {
                $inner .= $doc->saveHTML($child);
            }

            return trim($inner);
        }

        // No <body> found — return whatever survived the stripping.
        return trim((string) $doc->saveHTML());
    }
}
