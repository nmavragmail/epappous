<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central capability checks for Club admin AJAX and UI.
 */
class EPC_Capabilities {

    /**
     * Users who can manage Pappou Club in wp-admin (settings, members AJAX, profile boxes).
     */
    public static function current_user_can_manage_club(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }
        /**
         * Allow custom cap (e.g. shop_staff) without opening to any subscriber.
         */
        return (bool) apply_filters( 'epc_user_can_manage_club', false );
    }

    /**
     * Whether the current user may act on another WP user (notes, cassette, enroll).
     */
    public static function current_user_can_edit_wp_user( int $user_id ): bool {
        if ( $user_id < 1 ) {
            return false;
        }
        if ( ! self::current_user_can_manage_club() ) {
            return false;
        }
        return current_user_can( 'edit_user', $user_id );
    }
}
