<?php
/**
 * Plugin Name: ePappous Club
 * Plugin URI: https://epappous.gr
 * Description: Loyalty & membership club with referral tracking, gift products, and full settings management.
 * Version: 1.15.21
 * Author: 2NET
 * Author URI: https://2net.gr
 * Text Domain: epappous-club
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EPC_VERSION', '1.15.21' );
define( 'EPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EPC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once EPC_PLUGIN_DIR . 'includes/class-epc-database.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-dob-validator.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-capabilities.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-settings.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-b2bking.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-member-sync.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-referral.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-referral-clicks-cleanup.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-birthday.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-expiry.php';
// Tiers module disabled site-wide (no UI / no tier emails). Re-enable by uncommenting:
// require_once EPC_PLUGIN_DIR . 'includes/class-epc-tiers.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-notifications.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-woocommerce.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-registration.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-gift-products.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-user-profile.php';
require_once EPC_PLUGIN_DIR . 'includes/class-epc-admin-screen-options.php';
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
    EPC_Referral_Clicks_Cleanup::schedule();
}

function epc_deactivate() {
    EPC_Database::deactivate();
    EPC_Birthday::unschedule();
    EPC_Expiry::unschedule();
    EPC_Referral_Clicks_Cleanup::unschedule();
}

add_action( 'plugins_loaded', 'epc_init' );

function epc_init() {
    load_plugin_textdomain( 'epappous-club', false, dirname( EPC_PLUGIN_BASENAME ) . '/languages' );

    EPC_Database::maybe_upgrade();

    add_shortcode( 'epappous_points', 'epc_shortcode_current_user_points' );
    add_shortcode( 'epc_points', 'epc_shortcode_current_user_points' );
    add_shortcode( '2net_loyalty_points', 'epc_shortcode_current_user_points' );

    EPC_Referral_Clicks_Cleanup::schedule();

    EPC_Settings::instance();
    EPC_Member_Sync::instance();
    EPC_Referral::instance();
    EPC_Referral_Clicks_Cleanup::init();
    EPC_Birthday::instance();
    EPC_Expiry::instance();
    // EPC_Tiers::instance();
    EPC_Notifications::instance();
    EPC_WooCommerce::instance();
    EPC_Registration::instance();
    EPC_Gift_Products::instance();
    EPC_User_Profile::instance();

    EPC_Admin_Screen_Options::boot();
    EPC_Admin::instance();
}

/**
 * Return the current logged-in user's club points.
 *
 * Points are stored in the existing ePappous Club members table, not in a
 * separate plugin. The lookup prefers user_id and falls back to user email.
 */
function epc_get_current_user_points( int $user_id = 0 ): int {
    if ( $user_id < 1 ) {
        $user_id = get_current_user_id();
    }

    if ( $user_id < 1 ) {
        return 0;
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return 0;
    }

    global $wpdb;

    $points = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT points
             FROM {$wpdb->prefix}epc_members
             WHERE status = 'active'
               AND ( user_id = %d OR email = %s )
             ORDER BY CASE WHEN user_id = %d THEN 0 ELSE 1 END
             LIMIT 1",
            $user_id,
            $user->user_email,
            $user_id
        )
    );

    return max( 0, (int) $points );
}

/**
 * Shortcode: current user's ePappous Club points.
 *
 * Primary usage:
 *   [epappous_points]
 *
 * Aliases:
 *   [epc_points]
 *   [2net_loyalty_points]
 *
 * Attributes:
 *   guest="0"  Output for logged-out visitors. Default: 0.
 *   raw="1"    Return an unformatted integer.
 */
function epc_shortcode_current_user_points( $atts ): string {
    $atts = shortcode_atts(
        [
            'guest' => '0',
            'raw'   => '0',
        ],
        $atts,
        'epappous_points'
    );

    if ( ! is_user_logged_in() ) {
        return esc_html( (string) $atts['guest'] );
    }

    $points = epc_get_current_user_points();

    if ( '1' === (string) $atts['raw'] || 'true' === strtolower( (string) $atts['raw'] ) ) {
        return esc_html( (string) $points );
    }

    return esc_html( number_format_i18n( $points ) );
}
