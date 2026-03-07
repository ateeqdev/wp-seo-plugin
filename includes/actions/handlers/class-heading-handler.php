<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\Utils\Logger;

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
        $adjustments = isset($payload['adjustments']) && is_array($payload['adjustments']) ? $payload['adjustments'] : [];

        if (empty($adjustments)) {
            $snapshot = $this->capturePostSnapshot($postId);
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true, 'reason' => 'No heading adjustments'],
                'before' => $snapshot,
                'after' => $snapshot,
            ];
        }

        $before = $this->capturePostSnapshot($postId);

        try {
            $content = (string) $post->post_content;
            $blocks = parse_blocks($content);

            if (empty($blocks)) {
                throw new Exception('Heading adjustments require block content.');
            }

            $changes = [];
            $mutatedBlocks = $this->applyAdjustmentsToBlocks($blocks, $adjustments, $changes);

            if (empty($changes)) {
                return [
                    'status' => 'applied',
                    'metadata' => ['noop' => true, 'reason' => 'No matching headings found to adjust'],
                    'before' => $before,
                    'after' => $before,
                ];
            }

            $levels = [];
            $this->collectHeadingLevels($mutatedBlocks, $levels);
            $validation = $this->validateHierarchy($levels);
            if ($validation['valid'] === false) {
                throw new Exception('Resulting heading hierarchy invalid: ' . implode('; ', $validation['issues']));
            }

            $mutatedContent = serialize_blocks($mutatedBlocks);

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
                    'handler' => 'adjust_headings',
                    'adjustments_made' => count($changes),
                    'adjustments' => $changes,
                    'hierarchy_validated' => true,
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
     * @param array<int, mixed> $adjustments
     * @param array<int, array{heading:string,from:int,to:int}> $changes
     * @return array<int, array<string, mixed>>
     */
    private function applyAdjustmentsToBlocks(array $blocks, array $adjustments, array &$changes): array
    {
        foreach ($blocks as &$block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['blockName'] ?? '') === 'core/heading') {
                $currentLevel = (int) ($block['attrs']['level'] ?? 2);
                $headingText = wp_strip_all_tags((string) ($block['innerHTML'] ?? ''));

                foreach ($adjustments as $adjustment) {
                    if (!is_array($adjustment)) {
                        continue;
                    }

                    $targetText = wp_strip_all_tags((string) ($adjustment['heading_text'] ?? ''));
                    $from = (int) str_replace('h', '', strtolower((string) ($adjustment['current_level'] ?? 'h' . $currentLevel)));
                    $to = (int) str_replace('h', '', strtolower((string) ($adjustment['target_level'] ?? 'h' . $currentLevel)));

                    if ($targetText === '' || $to < 1 || $to > 6) {
                        continue;
                    }

                    if ($headingText === $targetText && $currentLevel === $from) {
                        $block['attrs']['level'] = $to;
                        $block['innerHTML'] = $this->replaceHeadingTags((string) ($block['innerHTML'] ?? ''), $from, $to);

                        if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                            foreach ($block['innerContent'] as &$part) {
                                if (is_string($part) && $part !== '') {
                                    $part = $this->replaceHeadingTags($part, $from, $to);
                                }
                            }
                            unset($part);
                        }

                        $changes[] = [
                            'heading' => $headingText,
                            'from' => $from,
                            'to' => $to,
                        ];
                        break;
                    }
                }
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->applyAdjustmentsToBlocks($block['innerBlocks'], $adjustments, $changes);
            }
        }
        unset($block);

        return $blocks;
    }

    private function replaceHeadingTags(string $html, int $from, int $to): string
    {
        if ($html === '') {
            return $html;
        }

        $updated = preg_replace('/<h' . $from . '(\s[^>]*)?>/i', '<h' . $to . '$1>', $html, 1);
        if (!is_string($updated)) {
            $updated = $html;
        }

        $updated = preg_replace('/<\/h' . $from . '>/i', '</h' . $to . '>', $updated, 1);

        return is_string($updated) ? $updated : $html;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param int[] $levels
     */
    private function collectHeadingLevels(array $blocks, array &$levels): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['blockName'] ?? '') === 'core/heading') {
                $levels[] = (int) ($block['attrs']['level'] ?? 2);
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectHeadingLevels($block['innerBlocks'], $levels);
            }
        }
    }

    /**
     * @param int[] $levels
     * @return array{valid:bool,issues:array<int,string>}
     */
    private function validateHierarchy(array $levels): array
    {
        $issues = [];

        $h1Count = count(array_filter($levels, static fn (int $level): bool => $level === 1));
        if ($h1Count > 1) {
            $issues[] = 'Multiple H1 headings detected.';
        }

        $prev = 0;
        foreach ($levels as $level) {
            if ($prev > 0 && ($level - $prev) > 1) {
                $issues[] = sprintf('Heading level jump from H%d to H%d.', $prev, $level);
            }
            $prev = $level;
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
        ];
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
