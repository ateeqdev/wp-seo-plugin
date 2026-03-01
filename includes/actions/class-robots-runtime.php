<?php

declare(strict_types=1);

namespace SEOAutomation\Connector\Actions;

final class RobotsRuntime
{
    public static function registerHooks(): void
    {
        add_filter('robots_txt', [self::class, 'appendDirectives'], 999, 2);
    }

    /**
     * @param bool $public
     */
    public static function appendDirectives(string $output, $public): string
    {
        if (!$public) {
            return $output;
        }

        $directives = get_option('seoauto_robots_directives', []);
        if (!is_array($directives) || empty($directives)) {
            return $output;
        }

        $output .= "\n# SEO Automation Custom Directives\n";

        foreach ($directives as $directive) {
            if (is_string($directive) && $directive !== '') {
                $output .= $directive . "\n";
            }
        }

        return $output;
    }
}
