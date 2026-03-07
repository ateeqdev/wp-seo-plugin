<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions\Handlers;

use SEOWorkerAI\Connector\Utils\Logger;

final class MonitorHandler extends AbstractActionHandler
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
        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'monitor_only',
                'note' => 'No mutation performed.',
            ],
            'before' => [],
            'after' => [],
        ];
    }
}
