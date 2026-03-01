<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\Utils\Logger;

final class BrokenLinkHandler extends AbstractActionHandler
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
        $broken = trim((string) ($payload['broken_url'] ?? ''));
        $replacement = trim((string) ($payload['replacement_url'] ?? ''));

        if ($broken === '' || $replacement === '') {
            throw new Exception('Missing broken_url or replacement_url.');
        }

        $beforeContent = (string) $post->post_content;
        if (strpos($beforeContent, $broken) === false) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true, 'reason' => 'broken_url_not_present'],
                'before' => ['post_content' => $beforeContent],
                'after' => ['post_content' => $beforeContent],
            ];
        }

        $afterContent = str_replace($broken, esc_url_raw($replacement), $beforeContent);

        wp_update_post([
            'ID' => $postId,
            'post_content' => $afterContent,
        ]);

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'fix_broken_link',
                'replacement_count' => substr_count($beforeContent, $broken),
            ],
            'before' => ['post_content' => $beforeContent],
            'after' => ['post_content' => (string) get_post_field('post_content', $postId)],
        ];
    }
}
