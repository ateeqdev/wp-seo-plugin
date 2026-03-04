<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Events;

use SEOAutomation\Connector\Utils\Logger;

final class EventCollector
{
    private EventMapper $mapper;

    private EventOutbox $outbox;

    private Logger $logger;

    public function __construct(EventMapper $mapper, EventOutbox $outbox, Logger $logger)
    {
        $this->mapper = $mapper;
        $this->outbox = $outbox;
        $this->logger = $logger;
    }

    public function registerHooks(): void
    {
        add_action('save_post_page', [$this, 'onPageSave'], 10, 3);
        add_action('save_post_post', [$this, 'onPostSave'], 10, 3);
        add_action('publish_post', [$this, 'onPostPublished'], 10, 2);
        add_action('save_post_product', [$this, 'onProductSave'], 10, 3);
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 2);
        add_action('add_attachment', [$this, 'onAttachmentAdd'], 10, 1);
        add_action('activated_plugin', [$this, 'onPluginActivated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'onPluginDeactivated'], 10, 2);
        add_action('switch_theme', [$this, 'onThemeSwitch'], 10, 3);
    }

    public function onPageSave(int $postId, \WP_Post $post, bool $update): void
    {
        $this->capturePostSave('page', $postId, $post, $update);
    }

    public function onPostSave(int $postId, \WP_Post $post, bool $update): void
    {
        $this->capturePostSave('post', $postId, $post, $update);
    }

    public function onPostPublished(int $postId, \WP_Post $post): void
    {
        if (!$this->eventsEnabled()) {
            return;
        }

        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        if ($this->isPostExcludedFromChangeAudit($post)) {
            return;
        }

        $this->outbox->queue($this->mapper->mapPostEvent('post_published', $post));
    }

    public function onProductSave(int $postId, \WP_Post $post, bool $update): void
    {
        $this->capturePostSave('product', $postId, $post, $update);
    }

    public function onBeforeDeletePost(int $postId, ?\WP_Post $post = null): void
    {
        if (!$this->eventsEnabled()) {
            return;
        }

        $post = $post ?: get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $eventType = null;
        if ($post->post_type === 'page') {
            $eventType = 'page_deleted';
        } elseif ($post->post_type === 'post') {
            $eventType = 'post_deleted';
        }

        if ($eventType === null) {
            return;
        }

        $this->outbox->queue($this->mapper->mapPostEvent($eventType, $post));
    }

    public function onAttachmentAdd(int $attachmentId): void
    {
        if (!$this->eventsEnabled()) {
            return;
        }

        $this->outbox->queue($this->mapper->mapAttachmentUploaded($attachmentId));
    }

    public function onPluginActivated(string $plugin, bool $networkWide): void
    {
        if (!$this->eventsEnabled()) {
            return;
        }

        $this->outbox->queue($this->mapper->mapSystemEvent('plugin_changed', [
            'plugin' => $plugin,
            'state' => 'activated',
            'network_wide' => $networkWide,
        ]));
    }

    public function onPluginDeactivated(string $plugin, bool $networkWide): void
    {
        if (!$this->eventsEnabled()) {
            return;
        }

        $this->outbox->queue($this->mapper->mapSystemEvent('plugin_changed', [
            'plugin' => $plugin,
            'state' => 'deactivated',
            'network_wide' => $networkWide,
        ]));
    }

    public function onThemeSwitch(string $newName, \WP_Theme $newTheme, ?\WP_Theme $oldTheme): void
    {
        if (!$this->eventsEnabled()) {
            return;
        }

        $this->outbox->queue($this->mapper->mapSystemEvent('theme_changed', [
            'new_theme' => $newName,
            'new_template' => $newTheme->get_template(),
            'old_template' => $oldTheme ? $oldTheme->get_template() : null,
        ]));
    }

    private function capturePostSave(string $type, int $postId, \WP_Post $post, bool $update): void
    {
        if (!$this->eventsEnabled()) {
            return;
        }

        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        if ($this->isPostExcludedFromChangeAudit($post)) {
            return;
        }

        $eventType = '';

        if ($type === 'page') {
            $eventType = $update ? 'page_updated' : 'page_created';
        }

        if ($type === 'post') {
            $eventType = $update ? 'post_updated' : 'post_published';
        }

        if ($type === 'product') {
            $eventType = $update ? 'product_updated' : 'product_created';
        }

        if ($eventType === '') {
            return;
        }

        $payload = $this->mapper->mapPostEvent($eventType, $post);
        $queued = $this->outbox->queue($payload);

        if (!$queued) {
            $this->logger->warning('event_queue_failed', [
                'entity_type' => 'post',
                'entity_id' => (string) $postId,
                'request_payload' => $payload,
            ], 'inbound');
        }
    }

    private function eventsEnabled(): bool
    {
        $features = (array) get_option('seoauto_features', []);

        return (bool) ($features['send_events'] ?? true);
    }

    private function isPostExcludedFromChangeAudit(\WP_Post $post): bool
    {
        $raw = (string) get_option('seoauto_excluded_change_audit_pages', '');
        if (trim($raw) === '') {
            return false;
        }

        $entries = preg_split('/[\r\n,]+/', $raw) ?: [];
        if ($entries === []) {
            return false;
        }

        $postId = (int) $post->ID;
        $postSlug = strtolower(trim((string) $post->post_name));
        $postUrl = rtrim((string) get_permalink($postId), '/');

        foreach ($entries as $entry) {
            $candidate = trim((string) $entry);
            if ($candidate === '') {
                continue;
            }

            if (ctype_digit($candidate) && (int) $candidate === $postId) {
                return true;
            }

            $normalized = strtolower($candidate);
            if ($postSlug !== '' && $normalized === $postSlug) {
                return true;
            }

            if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
                if (rtrim($candidate, '/') === $postUrl) {
                    return true;
                }
            }
        }

        return false;
    }
}
