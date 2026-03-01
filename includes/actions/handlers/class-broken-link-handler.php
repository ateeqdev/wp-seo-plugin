<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\Content\HtmlMutator;
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
        $brokenUrl = trim((string) ($payload['broken_url'] ?? ''));
        $replacementUrl = trim((string) ($payload['replacement_url'] ?? ''));

        if ($brokenUrl === '' || $replacementUrl === '') {
            throw new Exception('Missing broken_url or replacement_url.');
        }

        $brokenUrl = rtrim($brokenUrl, '/');
        $replacementUrl = rtrim(esc_url_raw($replacementUrl), '/');

        $before = $this->capturePostSnapshot($postId);

        try {
            $content = (string) $post->post_content;
            $blocks = parse_blocks($content);
            $hasBlocks = !empty($blocks);

            $totalReplacements = 0;
            $mutatedContent = $content;

            if ($hasBlocks) {
                $mutatedBlocks = $this->replaceUrlsInBlocks($blocks, $brokenUrl, $replacementUrl, $totalReplacements);
                $mutatedContent = serialize_blocks($mutatedBlocks);
            } else {
                $mutatedContent = HtmlMutator::replaceUrlReferences($content, $brokenUrl, $replacementUrl, $totalReplacements);
            }

            if ($totalReplacements === 0 || $mutatedContent === $content) {
                return [
                    'status' => 'applied',
                    'metadata' => ['noop' => true, 'reason' => 'broken_url_not_present'],
                    'before' => $before,
                    'after' => $before,
                ];
            }

            $result = wp_update_post([
                'ID' => $postId,
                'post_content' => $mutatedContent,
            ], true);

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            $after = $this->capturePostSnapshot($postId);

            return [
                'status' => 'applied',
                'metadata' => [
                    'handler' => 'fix_broken_link',
                    'broken_url' => $brokenUrl,
                    'replacement_url' => $replacementUrl,
                    'replacement_count' => $totalReplacements,
                    'block_safe' => $hasBlocks,
                ],
                'before' => $before,
                'after' => $after,
            ];
        } catch (\Throwable $exception) {
            $this->restorePostSnapshot($postId, $before);
            throw $exception;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function replaceUrlsInBlocks(array $blocks, string $brokenUrl, string $replacementUrl, int &$totalReplacements): array
    {
        foreach ($blocks as &$block) {
            if (!is_array($block)) {
                continue;
            }

            if (isset($block['innerHTML']) && is_string($block['innerHTML']) && $block['innerHTML'] !== '') {
                $count = 0;
                $block['innerHTML'] = HtmlMutator::replaceUrlReferences(
                    $block['innerHTML'],
                    $brokenUrl,
                    $replacementUrl,
                    $count
                );

                $totalReplacements += $count;

                if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                    foreach ($block['innerContent'] as &$part) {
                        if (!is_string($part) || $part === '') {
                            continue;
                        }

                        $partCount = 0;
                        $part = HtmlMutator::replaceUrlReferences($part, $brokenUrl, $replacementUrl, $partCount);
                        $totalReplacements += $partCount;
                    }
                    unset($part);
                }
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->replaceUrlsInBlocks(
                    $block['innerBlocks'],
                    $brokenUrl,
                    $replacementUrl,
                    $totalReplacements
                );
            }
        }
        unset($block);

        return $blocks;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        $postId = $this->resolvePostId($action);
        $rawBefore = isset($action['before_snapshot']) ? (string) $action['before_snapshot'] : '';
        $before = json_decode($rawBefore, true);

        if (!is_array($before) || empty($before)) {
            return ['status' => 'failed', 'error' => 'Missing before snapshot'];
        }

        $ok = $this->restorePostSnapshot($postId, $before);

        return ['status' => $ok ? 'rolled_back' : 'failed'];
    }
}
