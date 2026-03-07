<?php
/**
 * Plugin Name: SEOWorkerAI.com
 * Plugin URI: https://seoworkerai.com/
 * Description: Connects WordPress to SEOWorkerAI.com for SEO automation, content briefs, change execution, and analytics-backed recommendations.
 * Version: 2.0.0
 * Author: SEOWorkerAI.com
 * License: GPL-2.0-or-later
 * Text Domain: seoworkerai
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SEOAUTO_VERSION', '2.0.0');
define('SEOAUTO_PLUGIN_FILE', __FILE__);
define('SEOAUTO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEOAUTO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SEOAUTO_DB_VERSION', '1.2.2');
define('SEOAUTO_LARAVEL_BASE_URL', 'https://app.seoworkerai.com/');

require_once SEOAUTO_PLUGIN_DIR . 'includes/class-autoloader.php';
require_once SEOAUTO_PLUGIN_DIR . 'includes/class-activator.php';
require_once SEOAUTO_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once SEOAUTO_PLUGIN_DIR . 'includes/class-plugin.php';

SEOAutomation\Connector\Autoloader::register();

register_activation_hook(__FILE__, ['SEOAutomation\\Connector\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['SEOAutomation\\Connector\\Deactivator', 'deactivate']);

SEOAutomation\Connector\Plugin::instance()->boot();
