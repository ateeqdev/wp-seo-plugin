<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\Utils\Logger;

final class HeadingHandler extends AbstractActionHandler
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
        $adjustments = isset($payload['adjustments']) && is_array($payload['adjustments'])
            ? $payload['adjustments']
            : [];

        if (empty($adjustments)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true, 'reason' => 'No heading adjustments'],
                'before' => ['post_content' => (string) $post->post_content],
                'after' => ['post_content' => (string) $post->post_content],
            ];
        }

        $before = (string) $post->post_content;
        $blocks = parse_blocks($before);
        $changes = [];

        foreach ($blocks as &$block) {
            if (($block['blockName'] ?? '') !== 'core/heading') {
                continue;
            }

            $text = wp_strip_all_tags((string) ($block['innerHTML'] ?? ''));
            $level = (int) ($block['attrs']['level'] ?? 2);

            foreach ($adjustments as $adjustment) {
                if (!is_array($adjustment)) {
                    continue;
                }

                $targetText = wp_strip_all_tags((string) ($adjustment['heading_text'] ?? ''));
                $from = (int) str_replace('h', '', strtolower((string) ($adjustment['current_level'] ?? 'h' . $level)));
                $to = (int) str_replace('h', '', strtolower((string) ($adjustment['target_level'] ?? 'h' . $level)));

                if ($targetText === '' || $to < 1 || $to > 6) {
                    continue;
                }

                if ($text === $targetText && $level === $from) {
                    $block['attrs']['level'] = $to;
                    $changes[] = [
                        'heading' => $text,
                        'from' => $from,
                        'to' => $to,
                    ];
                    break;
                }
            }
        }
        unset($block);

        if (empty($changes)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true, 'reason' => 'No matching headings'],
                'before' => ['post_content' => $before],
                'after' => ['post_content' => $before],
            ];
        }

        $after = serialize_blocks($blocks);

        wp_update_post([
            'ID' => $postId,
            'post_content' => $after,
        ]);

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'adjust_headings',
                'adjustments_made' => count($changes),
            ],
            'before' => ['post_content' => $before],
            'after' => [
                'post_content' => (string) get_post_field('post_content', $postId),
                'adjustments' => $changes,
            ],
        ];
    }
}
