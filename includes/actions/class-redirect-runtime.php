<?php

declare(strict_types=1);

namespace SEOWorkerAI\Connector\Actions;

final class RedirectRuntime
{
    public static function registerHooks(): void
    {
        add_action('template_redirect', [self::class, 'handleRedirect'], 1);
    }

    public static function handleRedirect(): void
    {
        if (is_admin()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seoworkerai_redirects';

        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($host === '' || $requestUri === '') {
            return;
        }

        $currentUrl = (is_ssl() ? 'https://' : 'http://') . $host . $requestUri;
        $currentUrl = rtrim(esc_url_raw($currentUrl), '/');
        if ($currentUrl === '') {
            return;
        }

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare("SELECT * FROM {$table} WHERE source_url = %s", $currentUrl)
        );

        if (!$row || empty($row->target_url)) {
            return;
        }

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "UPDATE {$table} SET hit_count = hit_count + 1, last_hit = %s WHERE source_url = %s",
                current_time('mysql'),
                $currentUrl
            )
        );

        $status = ((int) $row->redirect_type) === 302 ? 302 : 301;
        wp_redirect((string) $row->target_url, $status);
        exit;
    }
}
