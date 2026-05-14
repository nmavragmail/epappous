<?php
/**
 * Plugin Name: 2NET Loyalty
 * Plugin URI:  https://2net.gr
 * Description: WooCommerce loyalty points system — earn points on purchases, redeem for discounts, gifts & coupons, referral rewards, birthday bonus, and more.
 * Version:     1.1.0
 * Author:      2NET
 * Author URI:  https://2net.gr
 * License:     GPLv2 or later
 * Text Domain: 2net-loyalty
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'TWONET_LOYALTY_VERSION', '1.1.0' );
define( 'TWONET_LOYALTY_FILE', __FILE__ );
define( 'TWONET_LOYALTY_PATH', plugin_dir_path( __FILE__ ) );
define( 'TWONET_LOYALTY_URL', plugin_dir_url( __FILE__ ) );
define( 'TWONET_LOYALTY_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check that WooCommerce is active before bootstrapping.
 */
function twonet_loyalty_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>2NET Loyalty</strong> requires WooCommerce to be installed and active.</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function twonet_loyalty_init() {
    if ( ! twonet_loyalty_check_woocommerce() ) {
        return;
    }

    require_once TWONET_LOYALTY_PATH . 'includes/class-loyalty-core.php';
    require_once TWONET_LOYALTY_PATH . 'includes/class-points-manager.php';
    require_once TWONET_LOYALTY_PATH . 'includes/class-order-handler.php';
    require_once TWONET_LOYALTY_PATH . 'includes/class-redemption-handler.php';
    require_once TWONET_LOYALTY_PATH . 'includes/class-bonus-handler.php';
    require_once TWONET_LOYALTY_PATH . 'includes/class-myaccount.php';

    if ( is_admin() ) {
        require_once TWONET_LOYALTY_PATH . 'includes/class-admin.php';
    }

    TwoNet_Loyalty_Core::instance();
    TwoNet_Points_Manager::instance();
    TwoNet_Order_Handler::instance();
    TwoNet_Redemption_Handler::instance();
    TwoNet_Bonus_Handler::instance();
    TwoNet_MyAccount::instance();

    if ( is_admin() ) {
        TwoNet_Loyalty_Admin::instance();
    }
}
add_action( 'plugins_loaded', 'twonet_loyalty_init', 20 );

/**
 * Activation hook — create DB tables and set defaults.
 */
register_activation_hook( __FILE__, function () {
    require_once TWONET_LOYALTY_PATH . 'includes/class-loyalty-core.php';
    TwoNet_Loyalty_Core::activate();
} );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
