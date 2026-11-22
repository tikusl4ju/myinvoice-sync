<?php
/**
 * Plugin Name: MyInvoice Sync
 * Plugin URI: https://github.com/tikusl4ju/myinvoice-sync
 * Description: LHDN MyInvois Auto Submission
 * Author: TikusL4ju
 * Version: 2.1.4
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 7.1
 * WC tested up to: 11.3
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: myinvoice-sync
 */

if (!defined('ABSPATH')) exit;

if (!defined('MYINVOICE_SYNC_VERSION')) {
    define('MYINVOICE_SYNC_VERSION', '2.1.4');
}

if (!defined('MYINVOICE_SYNC_FILE')) {
    define('MYINVOICE_SYNC_FILE', __FILE__);
}

// Declare compatibility with WooCommerce HPOS (custom order tables) and Cart/Checkout blocks
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', MYINVOICE_SYNC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', MYINVOICE_SYNC_FILE, true);
    }
});

// Load main plugin class
require_once plugin_dir_path(__FILE__) . 'includes/class-lhdn-plugin.php';

// Initialize plugin
LHDN_MyInvoice_Plugin::get_instance();
