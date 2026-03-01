<?php

declare(strict_types=1);

namespace SEOAutomation\Connector;

final class Autoloader
{
    private const PREFIX = 'SEOAutomation\\Connector\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    /**
     * @param string $class
     */
    private static function autoload(string $class): void
    {
        if (strpos($class, self::PREFIX) !== 0) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        if ($relative === false || $relative === '') {
            return;
        }

        $parts = explode('\\', $relative);
        $className = array_pop($parts);
        $directory = '';

        if (!empty($parts)) {
            $directory = strtolower(implode('/', $parts)) . '/';
        }

        // Split only on lower/digit -> upper boundaries so acronyms like OAuth stay intact.
        $normalized = (string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $className);
        $fileName = 'class-' . strtolower($normalized) . '.php';
        $path = SEOAUTO_PLUGIN_DIR . 'includes/' . $directory . $fileName;

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
