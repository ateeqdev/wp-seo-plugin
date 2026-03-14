<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Events;

use SEOWorkerAI\Connector\SEO\SeoDetector;

final class EventMapper
{
    /**
     * Maps a WordPress post/page to a canonical event payload.
     *
     * @return array<string, mixed>
     */
    public function mapPostEvent(string $eventType, \WP_Post $post): array
    {
        $adapter = SeoDetector::instance()->getAdapter();
        $featuredImageId = (int) get_post_thumbnail_id($post->ID);
        $authorId = (int) $post->post_author;
        $author = $authorId > 0 ? get_userdata($authorId) : false;

        return [
            'event_type' => $eventType,
            'post_id' => $post->ID,
            'post_type' => $post->post_type,
            'post_url' => get_permalink($post->ID),
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_meta' => [
                'seo_title' => $adapter->getTitle($post->ID),
                'seo_description' => $adapter->getDescription($post->ID),
                'canonical' => $adapter->getCanonical($post->ID),
                'schema_markup' => $adapter->getSchema($post->ID),
                'published_at' => get_post_time('c', true, $post->ID),
                'modified_at' => get_post_modified_time('c', true, $post->ID),
                'featured_image' => $featuredImageId > 0 ? (string) wp_get_attachment_url($featuredImageId) : '',
                'social_tags' => $adapter->getSocialTags($post->ID),
                'author' => [
                    'id' => $authorId,
                    'name' => $author instanceof \WP_User ? (string) $author->display_name : '',
                    'twitter_handle' => $authorId > 0 ? (string) get_user_meta($authorId, '_seoworkerai_author_twitter_handle', true) : '',
                ],
            ],
            'event_time' => gmdate('c'),
        ];
    }

    /**
     * Maps a theme-rendered URL (no post_id) to an event payload.
     *
     * These are pages rendered entirely by the theme — homepage, archives,
     * category/tag/taxonomy pages, author pages, etc. — that have no backing
     * post record. The payload includes SEO metadata from both the active SEO
     * adapter (for post_id=0 globals) and the UrlMetaStore (for any overrides
     * that have been applied via the action system).
     *
     * @param  string  $url  The canonical URL of the rendered page.
     * @param  string  $page_type  A stable label (e.g. "home", "archive_category").
     * @return array<string, mixed>
     */
    public function mapRenderedUrlEvent(string $url, string $page_type = 'page'): array
    {
        $adapter = SeoDetector::instance()->getAdapter();
        $urlStore = new \SEOWorkerAI\Connector\Storage\UrlMetaStore;

        // Collect any stored overrides for this URL.
        $storedTitle = (string) ($urlStore->getMeta($url, 'title') ?? '');
        $storedDescription = (string) ($urlStore->getMeta($url, 'meta_description') ?? '');
        $storedCanonical = (string) ($urlStore->getMeta($url, 'canonical') ?? '');
        $storedRobots = $urlStore->getMeta($url, 'robots');
        $storedSchema = $urlStore->getMeta($url, 'schema_json_ld');

        // Resolve effective SEO values: stored override takes precedence over
        // whatever the adapter returns for the global context (post_id = 0).
        $seoTitle = $storedTitle !== '' ? $storedTitle : (string) ($adapter->getTitle(0) ?? '');
        $seoDescription = $storedDescription !== '' ? $storedDescription : (string) ($adapter->getDescription(0) ?? '');
        $seoCanonical = $storedCanonical !== '' ? $storedCanonical : (string) ($adapter->getCanonical(0) ?? '');

        // Attempt to pull the page's rendered <title> from the wp_title filter
        // when no explicit SEO title is known yet. This gives Laravel something
        // meaningful to audit even before any action has been applied.
        $displayTitle = $this->resolveDisplayTitle($seoTitle, $url);

        return [
            'event_type' => 'page_discovered',
            'post_id' => null,
            'post_type' => $this->normalisePageType($page_type),
            'post_url' => $url,
            'post_title' => $displayTitle,
            'post_content' => '',
            'post_meta' => [
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription,
                'canonical_url' => $seoCanonical,
                'robots' => is_array($storedRobots) ? $storedRobots : (string) ($adapter->getRobots(0) ?? ''),
                'schema_markup' => is_array($storedSchema) ? $storedSchema : $adapter->getSchema(0),
                'social_tags' => $adapter->getSocialTags(0),
                'page_type' => $page_type,
            ],
            'event_time' => gmdate('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapAttachmentUploaded(int $attachmentId): array
    {
        $attachment = get_post($attachmentId);

        return [
            'event_type' => 'attachment_uploaded',
            'post_id' => $attachmentId,
            'post_type' => 'attachment',
            'post_url' => wp_get_attachment_url($attachmentId),
            'event_data' => [
                'attachment_id' => $attachmentId,
                'attachment_url' => wp_get_attachment_url($attachmentId),
                'file_path' => get_attached_file($attachmentId),
                'mime_type' => $attachment ? $attachment->post_mime_type : '',
            ],
            'event_time' => gmdate('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSystemEvent(string $eventType, array $eventData = []): array
    {
        return [
            'event_type' => $eventType,
            'event_data' => $eventData,
            'event_time' => gmdate('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @deprecated Use mapRenderedUrlEvent() for theme-rendered pages.
     *             Kept for backward compatibility with any callers.
     */
    public function mapUrlEvent(string $url): array
    {
        return $this->mapRenderedUrlEvent($url, 'page');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves a human-readable title for non-singular pages.
     *
     * Order of preference:
     *  1. An explicit SEO title already stored (adapter or UrlMetaStore override).
     *  2. The WordPress wp_title() result for the current request.
     *  3. The site name.
     */
    private function resolveDisplayTitle(string $seoTitle, string $url): string
    {
        if ($seoTitle !== '') {
            return $seoTitle;
        }

        // wp_title() only returns something meaningful during a template context.
        $wpTitle = trim((string) wp_title('|', false, 'right'));
        if ($wpTitle !== '') {
            return $wpTitle;
        }

        return (string) get_bloginfo('name');
    }

    /**
     * Maps internal page-type labels to the post_type values Laravel expects.
     * Archives and taxonomy pages are reported as 'page' since they have no
     * post_type in the WordPress sense; Laravel uses post_url for identification.
     */
    private function normalisePageType(string $pageType): string
    {
        return match ($pageType) {
            'home' => 'page',
            'archive_category',
            'archive_tag',
            'archive_taxonomy',
            'archive_author',
            'archive_date',
            'archive' => 'page',
            'search' => 'page',
            '404' => 'page',
            default => 'page',
        };
    }
}
