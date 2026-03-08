<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Events;

use SEOWorkerAI\Connector\SEO\SeoDetector;

final class EventMapper
{
    /**
     * @param \WP_Post $post
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

}
