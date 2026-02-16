<?php
/**
 * Plugin Name: Product Research
 * Plugin URI:  https://www.bbioon.com
 * Description: AI-powered competitive intelligence for WooCommerce products. Analyze competitor pricing, variations, and features directly from the product edit page.
 * Version:     1.0.0
 * Author:      Ahmad Wael
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: product-research
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 *
 * @package ProductResearch
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Plugin constants.
 *
 * @since 1.0.0
 *
 * @var string PR_VERSION        Semantic version of the plugin.
 * @var string PR_PLUGIN_FILE    Absolute path to the main plugin file.
 * @var string PR_PLUGIN_DIR     Absolute path to the plugin directory (with trailing slash).
 * @var string PR_PLUGIN_URL     URL to the plugin directory (with trailing slash).
 * @var string PR_PLUGIN_BASENAME Plugin basename for hook registration (e.g. 'product-research/product-research.php').
 */
define('PR_VERSION', '1.0.0');
define('PR_PLUGIN_FILE', __FILE__);
define('PR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PR_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check PHP version.
 *
 * Displays an admin notice and halts plugin loading if the server
 * runs a PHP version below 8.1.
 *
 * @since 1.0.0
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Product Research requires PHP 8.1 or higher. Please upgrade your PHP version.', 'product-research')
        );
    });
    return;
}

/**
 * Load Composer autoloader.
 *
 * Displays an admin notice if the vendor directory is missing
 * (i.e. `composer install` has not been run).
 *
 * @since 1.0.0
 */
$autoloader = PR_PLUGIN_DIR . 'vendor/autoload.php';

if (! file_exists($autoloader)) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Product Research requires Composer dependencies. Please run "composer install" in the plugin directory.', 'product-research')
        );
    });
    return;
}

require_once $autoloader;

/**
 * Initialize the plugin on plugins_loaded to ensure WooCommerce is available.
 *
 * Bootstraps the service container and plugin orchestrator. Hooked at
 * priority 20 to guarantee WooCommerce has loaded first.
 *
 * @since 1.0.0
 */
add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('Product Research requires WooCommerce to be installed and activated.', 'product-research')
            );
        });
        return;
    }

    $container = new \ProductResearch\Container();
    $plugin    = new \ProductResearch\Plugin($container);
    $plugin->init();
}, 20);
