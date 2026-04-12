<?php
/**
 * 2NET Loyalty — Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin data (options, user meta, custom tables).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove options.
delete_option( '2net_loyalty_settings' );
delete_option( '2net_loyalty_db_version' );

// Remove user meta.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (
    '_2net_loyalty_balance',
    '_2net_loyalty_referral_code',
    '_2net_loyalty_referred_by',
    '_2net_loyalty_welcome_coupon_sent',
    '_2net_last_birthday_award'
)" );

// Drop custom table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}2net_loyalty_log" );
