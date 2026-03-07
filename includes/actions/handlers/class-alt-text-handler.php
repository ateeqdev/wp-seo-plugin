<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use Exception;
use SEOWorkerAI\Connector\Utils\Logger;

final class AltTextHandler extends AbstractActionHandler
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
        $attachmentId = $this->resolveAttachmentId($action);

        if ($attachmentId <= 0) {
            return new \WP_Error('missing_attachment', 'No attachment ID provided.');
        }

        $attachment = get_post($attachmentId);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new \WP_Error('missing_attachment', 'Attachment not found.');
        }

        if (!wp_attachment_is_image($attachmentId)) {
            return new \WP_Error('not_image', 'Attachment is not an image.');
        }

        return true;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $attachmentId = $this->resolveAttachmentId($action);
        $payload = $this->payload($action);
        $alt = (string) ($payload['alt_text'] ?? $payload['suggested_alt'] ?? $payload['short_alt_text'] ?? '');
        $alt = $this->sanitizeText($alt);

        if ($alt === '') {
            throw new Exception('No alt text provided.');
        }

        if (strlen($alt) > 125) {
            $alt = substr($alt, 0, 125);
        }

        $before = [
            'alt_text' => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
        ];

        if (trim($before['alt_text']) === trim($alt)) {
            return [
                'status' => 'applied',
                'metadata' => ['noop' => true],
                'before' => $before,
                'after' => $before,
            ];
        }

        update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);

        $after = [
            'alt_text' => (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true),
        ];

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'add_alt_text',
                'confidence' => $payload['confidence'] ?? null,
            ],
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function rollback(array $action): array
    {
        $attachmentId = $this->resolveAttachmentId($action);
        $before = isset($action['before_snapshot']) ? json_decode((string) $action['before_snapshot'], true) : [];
        $previous = is_array($before) ? (string) ($before['alt_text'] ?? '') : '';

        update_post_meta($attachmentId, '_wp_attachment_image_alt', $previous);

        return ['status' => 'rolled_back'];
    }

    /**
     * @param array<string, mixed> $action
     */
    private function resolveAttachmentId(array $action): int
    {
        $target = isset($action['target_id']) ? (int) $action['target_id'] : 0;
        if ($target > 0) {
            return $target;
        }

        $payload = $this->payload($action);

        return isset($payload['attachment_id']) ? (int) $payload['attachment_id'] : 0;
    }
}
