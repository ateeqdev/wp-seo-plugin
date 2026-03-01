<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions\Handlers;

use Exception;
use SEOAutomation\Connector\Utils\Logger;

final class RobotsHandler extends AbstractActionHandler
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
        $payload = $this->payload($action);
        $directives = isset($payload['directives']) && is_array($payload['directives']) ? $payload['directives'] : [];

        if (empty($directives)) {
            throw new Exception('No directives provided.');
        }

        $sanitized = [];
        foreach ($directives as $directive) {
            if (!is_string($directive)) {
                continue;
            }

            $line = trim(wp_strip_all_tags($directive));
            if ($line !== '') {
                $sanitized[] = $line;
            }
        }

        update_option('seoauto_robots_directives', $sanitized, false);

        return [
            'status' => 'applied',
            'metadata' => [
                'handler' => 'robots_directives',
                'directives_count' => count($sanitized),
            ],
            'before' => [],
            'after' => ['directives' => $sanitized],
        ];
    }
}
