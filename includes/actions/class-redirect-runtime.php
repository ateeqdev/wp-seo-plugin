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

        $currentUrl = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $currentUrl = rtrim(esc_url_raw($currentUrl), '/');

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
