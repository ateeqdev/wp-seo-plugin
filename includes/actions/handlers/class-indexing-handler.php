<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use SEOWorkerAI\Connector\Utils\Logger;

final class IndexingHandler extends AbstractActionHandler
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function execute(array $action): array
    {
        $url = isset($action['target_url']) && is_string($action['target_url']) && $action['target_url'] !== ''
            ? $action['target_url']
            : home_url('/');

        $payload = $this->payload($action);
        if (!empty($payload['url']) && is_string($payload['url'])) {
            $url = $payload['url'];
        }

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'submit_for_indexing',
                'url' => esc_url_raw($url),
                'logged' => true,
                'note' => 'Submission is handled by Laravel.',
            ],
            'before' => [],
            'after' => ['url' => esc_url_raw($url)],
        ];
    }
}
