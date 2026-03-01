<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/../includes/class-autoloader.php';
if (!defined('SEOAUTO_PLUGIN_DIR')) {
    define('SEOAUTO_PLUGIN_DIR', realpath(__DIR__ . '/..') . '/');
}
SEOAutomation\Connector\Autoloader::register();
