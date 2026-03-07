<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\REST;

use SEOWorkerAI\Connector\Auth\SiteTokenManager;
use SEOWorkerAI\Connector\Utils\Logger;

final class RestAccessCompatibility
{
    private SiteTokenManager $tokenManager;

    private Logger $logger;

    public function __construct(SiteTokenManager $tokenManager, Logger $logger)
    {
        $this->tokenManager = $tokenManager;
        $this->logger = $logger;
    }

    public function registerHooks(): void
    {
        add_filter('rest_authentication_errors', [$this, 'allowSeoAutoRequests'], PHP_INT_MAX);
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function allowSeoAutoRequests($result)
    {
        if (empty($result)) {
            return $result;
        }

        $route = $this->detectRestRoute();
        if ($route === '' || !$this->isSeoAutoRoute($route)) {
            return $result;
        }

        if ($this->isOwnershipProofRoute($route)) {
            return null;
        }

        $token = $this->readInboundToken();
        if ($token === '' || !$this->tokenManager->verifyInboundToken($token)) {
            return $result;
        }

        $this->logger->info('rest_auth_bypass_for_seoworkerai_route', [
            'entity_type' => 'route',
            'entity_id' => $route,
        ], 'inbound');

        return null;
    }

    private function detectRestRoute(): string
    {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        global $wp;

        if (isset($wp) && is_object($wp) && isset($wp->query_vars['rest_route'])) {
            $route = (string) $wp->query_vars['rest_route'];
            if ($route !== '') {
                return '/' . ltrim($route, '/');
            }
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($uri === '') {
            return '';
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        $prefix = '/' . trim((string) rest_get_url_prefix(), '/') . '/';
        $position = strpos($path, $prefix);

        if ($position === false) {
            return '';
        }

        return '/' . ltrim(substr($path, $position + strlen($prefix)), '/');
    }

    private function isSeoAutoRoute(string $route): bool
    {
        return strpos($route, '/seoworkerai/v1/') === 0 || strpos($route, '/seo-platform/v1/') === 0;
    }

    private function isOwnershipProofRoute(string $route): bool
    {
        return strpos($route, '/seoworkerai/v1/ownership-proof') === 0
            || strpos($route, '/seo-platform/v1/ownership-proof') === 0;
    }

    private function readInboundToken(): string
    {
        $serverValue = $_SERVER['HTTP_X_SITE_TOKEN'] ?? '';
        if (!is_string($serverValue)) {
            return '';
        }

        return trim((string) wp_unslash($serverValue));
    }
}
