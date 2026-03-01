<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\Utils\Logger;

final class InternalLinkHandler extends AbstractActionHandler
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $action
     * @return bool|\WP_Error
     */
    public function validate(array $action)
    {
        $postId = $this->resolvePostId($action);
        $post = get_post($postId);

        if (!$post || $post->post_status === 'trash') {
            return new \WP_Error('missing_post', 'Target post not found.');
        }

        return true;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            throw new Exception('Post not found.');
        }

        $payload = $this->payload($action);
        $suggestions = [];

        if (isset($payload['suggestions']) && is_array($payload['suggestions'])) {
            $suggestions = $payload['suggestions'];
        } elseif (isset($payload['to_url'])) {
            $suggestions = [$payload];
        }

        if (empty($suggestions)) {
            throw new Exception('No link suggestions provided.');
        }

        $beforeContent = (string) $post->post_content;
        $content = $beforeContent;
        $applied = [];

        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }

            $toUrl = esc_url_raw((string) ($suggestion['to_url'] ?? ''));
            $anchorText = trim((string) ($suggestion['anchor_text'] ?? ''));

            if ($toUrl === '' || $anchorText === '') {
                continue;
            }

            if (strpos($content, $toUrl) !== false) {
                continue;
            }

            $anchorHtml = sprintf('<a href="%s">%s</a>', esc_url($toUrl), esc_html($anchorText));
            $inserted = false;

            $pattern = '/(' . preg_quote($anchorText, '/') . ')/i';
            if (preg_match($pattern, $content) === 1) {
                $content = preg_replace($pattern, $anchorHtml, $content, 1) ?? $content;
                $inserted = true;
            }

            if (!$inserted) {
                $content = rtrim($content) . "\n\n<p>" . $anchorHtml . '</p>';
            }

            $applied[] = [
                'to_url' => $toUrl,
                'anchor_text' => $anchorText,
            ];
        }

        if (empty($applied)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true, 'reason' => 'No insertion points'],
                'before' => ['post_content' => $beforeContent],
                'after' => ['post_content' => $beforeContent],
            ];
        }

        wp_update_post([
            'ID' => $postId,
            'post_content' => $content,
        ]);

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'add_internal_link',
                'links_added' => count($applied),
            ],
            'before' => ['post_content' => $beforeContent],
            'after' => [
                'post_content' => (string) get_post_field('post_content', $postId),
                'links' => $applied,
            ],
        ];
    }
}
