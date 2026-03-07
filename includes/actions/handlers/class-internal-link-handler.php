<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\Content\HtmlMutator;
use SEOWorkerAI\Connector\Utils\Logger;

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

        $suggestions = $this->normalizeSuggestions($this->payload($action));

        if (empty($suggestions)) {
            throw new Exception('No link suggestions provided.');
        }

        $before = $this->capturePostSnapshot($postId);

        try {
            $content = (string) $post->post_content;
            $blocks = parse_blocks($content);
            $hasBlocks = !empty($blocks);

            $applied = [];

            if ($hasBlocks) {
                $mutatedBlocks = $this->applySuggestionsToBlocks($blocks, $suggestions, $applied);
                $mutatedContent = serialize_blocks($mutatedBlocks);
            } else {
                $mutatedContent = $this->applySuggestionsToRawContent($content, $suggestions, $applied);
            }

            if (empty($applied) || $mutatedContent === $content) {
                return [
                    'status' => 'applied',
                    'metadata' => ['noop' => true, 'reason' => 'No insertion points found'],
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
                    'handler' => 'add_internal_link',
                    'links_added' => count($applied),
                    'block_safe' => $hasBlocks,
                    'links' => $applied,
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
     * @param array<string, mixed> $payload
     * @return array<int, array{to_url:string,anchor_text:string}>
     */
    private function normalizeSuggestions(array $payload): array
    {
        $input = [];

        if (isset($payload['suggestions']) && is_array($payload['suggestions'])) {
            $input = $payload['suggestions'];
        } elseif (isset($payload['to_url'])) {
            $input = [$payload];
        }

        $normalized = [];

        foreach ($input as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }

            $toUrl = rtrim(esc_url_raw((string) ($suggestion['to_url'] ?? '')), '/');
            $anchorText = trim(wp_strip_all_tags((string) ($suggestion['anchor_text'] ?? '')));

            if ($toUrl === '' || $anchorText === '') {
                continue;
            }

            $normalized[] = [
                'to_url' => $toUrl,
                'anchor_text' => $anchorText,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array{to_url:string,anchor_text:string}> $suggestions
     * @param array<int, array{to_url:string,anchor_text:string,insertion:string}> $applied
     * @return array<int, array<string, mixed>>
     */
    private function applySuggestionsToBlocks(array $blocks, array $suggestions, array &$applied): array
    {
        foreach ($suggestions as $suggestion) {
            $inserted = false;
            $blocks = $this->insertSuggestionIntoBlocks($blocks, $suggestion, $inserted, $applied);
        }

        return $blocks;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array{to_url:string,anchor_text:string} $suggestion
     * @param array<int, array{to_url:string,anchor_text:string,insertion:string}> $applied
     * @return array<int, array<string, mixed>>
     */
    private function insertSuggestionIntoBlocks(array $blocks, array $suggestion, bool &$inserted, array &$applied): array
    {
        foreach ($blocks as &$block) {
            if (!is_array($block)) {
                continue;
            }

            if ($inserted) {
                break;
            }

            $blockName = isset($block['blockName']) ? (string) $block['blockName'] : '';
            if ($blockName === 'core/paragraph' && isset($block['innerHTML']) && is_string($block['innerHTML'])) {
                if (stripos($block['innerHTML'], $suggestion['to_url']) === false) {
                    $byMatch = false;
                    $mutated = HtmlMutator::insertInternalLink(
                        $block['innerHTML'],
                        $suggestion['anchor_text'],
                        $suggestion['to_url'],
                        $byMatch
                    );

                    if ($mutated !== $block['innerHTML']) {
                        $block['innerHTML'] = $mutated;
                        if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                            $block['innerContent'] = [$mutated];
                        }
                        $inserted = true;
                        $applied[] = [
                            'to_url' => $suggestion['to_url'],
                            'anchor_text' => $suggestion['anchor_text'],
                            'insertion' => $byMatch ? 'anchor_match' : 'append',
                        ];
                    }
                }
            }

            if (!$inserted && isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->insertSuggestionIntoBlocks(
                    $block['innerBlocks'],
                    $suggestion,
                    $inserted,
                    $applied
                );
            }
        }
        unset($block);

        return $blocks;
    }

    /**
     * @param array<int, array{to_url:string,anchor_text:string}> $suggestions
     * @param array<int, array{to_url:string,anchor_text:string,insertion:string}> $applied
     */
    private function applySuggestionsToRawContent(string $content, array $suggestions, array &$applied): string
    {
        $mutated = $content;

        foreach ($suggestions as $suggestion) {
            if (stripos($mutated, $suggestion['to_url']) !== false) {
                continue;
            }

            $byMatch = false;
            $next = HtmlMutator::insertInternalLink($mutated, $suggestion['anchor_text'], $suggestion['to_url'], $byMatch);
            if ($next !== $mutated) {
                $mutated = $next;
                $applied[] = [
                    'to_url' => $suggestion['to_url'],
                    'anchor_text' => $suggestion['anchor_text'],
                    'insertion' => $byMatch ? 'anchor_match' : 'append',
                ];
            }
        }

        return $mutated;
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
