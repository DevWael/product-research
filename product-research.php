<?php
/**
 * Plugin Name: Product Research
 * Plugin URI:  https://www.bbioon.com
 * Description: AI-powered competitive intelligence for WooCommerce products. Analyze competitor pricing, variations, and features directly from the product edit page.
 * Version:     1.0.0
 * Author:      Ahmad Wael
 * Author URI:  https://www.bbioon.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: product-research
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 7.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Plugin constants.
 */
define('PR_VERSION', '1.0.0');
define('PR_PLUGIN_FILE', __FILE__);
define('PR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PR_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check PHP version.
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
