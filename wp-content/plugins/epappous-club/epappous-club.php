<?php
/**
 * Plugin Name: ePappous Club
 * Plugin URI: https://epappous.gr
 * Description: Loyalty & membership club with referral tracking, gift products, and full settings management.
 * Version: 1.10.16
 * Author: ePappous
 * Author URI: https://epappous.gr
 * Text Domain: epappous-club
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EPC_VERSION', '1.10.16' );
define( 'EPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EPC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once EPC_PLUGIN_DIR . 'includes/class-epc-database.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-settings.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-b2bking.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-member-sync.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-referral.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-gifts.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-birthday.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-expiry.php';
// Tiers module disabled site-wide (no UI / no tier emails). Re-enable by uncommenting:
// require_once EPC_PLUGIN_DIR . 'includes/class-epc-tiers.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-notifications.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-woocommerce.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-registration.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-gift-catalog.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-gift-rules.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-user-profile.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-admin.php';

register_activation_hook( __FILE__, 'epc_activate' );
register_deactivation_hook( __FILE__, 'epc_deactivate' );

add_action( 'before_woocommerce_init', 'epc_declare_woocommerce_compatibility' );

function epc_declare_woocommerce_compatibility() {
    if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        return;
    }

    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
}

function epc_activate() {
    EPC_Database::activate();
    EPC_Birthday::schedule();
    EPC_Expiry::schedule();
}

function epc_deactivate() {
    EPC_Database::deactivate();
    EPC_Birthday::unschedule();
    EPC_Expiry::unschedule();
}

add_action( 'plugins_loaded', 'epc_init' );

function epc_init() {
    load_plugin_textdomain( 'epappous-club', false, dirname( EPC_PLUGIN_BASENAME ) . '/languages' );

    EPC_Database::maybe_upgrade();

    EPC_Settings::instance();
    EPC_Member_Sync::instance();
    EPC_Referral::instance();
    EPC_Gifts::instance();
    EPC_Birthday::instance();
    EPC_Expiry::instance();
    // EPC_Tiers::instance();
    EPC_Notifications::instance();
    EPC_WooCommerce::instance();
    EPC_Registration::instance();
    EPC_Gift_Catalog::instance();
    EPC_Gift_Rules::instance();
    EPC_User_Profile::instance();
    EPC_Admin::instance();
}
