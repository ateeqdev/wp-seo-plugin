<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\API;

use RuntimeException;

final class RetryPolicy
{
    private int $maxAttempts;

    /**
     * @var int[]
     */
    private array $backoffSchedule;

    /**
     * @param int[] $backoffSchedule
     */
    public function __construct(int $maxAttempts = 5, array $backoffSchedule = [2, 5, 15, 30, 60])
    {
        $this->maxAttempts = $maxAttempts;
        $this->backoffSchedule = $backoffSchedule;
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function execute(callable $operation)
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (RuntimeException $exception) {
                $attempt++;

                if (!$this->shouldRetry($exception, $attempt)) {
                    throw $exception;
                }

                $delay = $this->nextDelaySeconds($attempt);
                usleep($delay * 1000000);
            }
        }
    }

    private function shouldRetry(RuntimeException $exception, int $attempt): bool
    {
        if ($this->isInteractiveAdminRequest()) {
            return false;
        }

        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        $code = (int) $exception->getCode();

        if ($code === 0 || $code === 429) {
            return true;
        }

        return $code >= 500;
    }

    private function isInteractiveAdminRequest(): bool
    {
        if (!function_exists('is_admin') || !is_admin()) {
            return false;
        }

        $is_ajax_request = function_exists('wp_doing_ajax') && wp_doing_ajax();
        $is_cron_request = function_exists('wp_doing_cron') && wp_doing_cron();

        return !$is_ajax_request && !$is_cron_request;
    }

    private function nextDelaySeconds(int $attempt): int
    {
        $idx = max(0, $attempt - 1);
        $base = $this->backoffSchedule[$idx] ?? end($this->backoffSchedule);
        $base = (int) $base;
        $jitter = random_int(0, 1000) / 1000;

        return (int) ceil($base + $jitter);
    }
}
