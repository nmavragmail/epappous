<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * B2B King integration — Pappou Club is defined by a single B2B group ID (configurable).
 *
 * @see https://woocommerce-b2b-plugin.com/docs/developer-documentation-hooks-functions-custom-code/
 *      b2bking()->get_user_group( $user_id )
 */
class EPC_B2BKing {

    /**
     * Whether B2B King is loaded and callable.
     */
    public static function is_active(): bool {
        return function_exists( 'b2bking' ) && is_object( b2bking() );
    }

    /**
     * Configured group post ID (B2B King groups are CPTs — ID may differ after migration; set in admin).
     */
    public static function get_configured_group_id(): int {
        return max( 0, (int) EPC_Settings::get( 'epc_b2bking_club_group_id' ) );
    }

    /**
     * User must belong to the configured B2B group (and be logged-in WordPress user).
     * Guests and other groups cannot earn or redeem.
     */
    public static function user_in_pappou_club( int $user_id ): bool {
        if ( $user_id < 1 ) {
            return false;
        }
        $expected = self::get_configured_group_id();
        if ( $expected < 1 ) {
            return false;
        }
        if ( ! self::is_active() ) {
            return false;
        }

        $group_id = (int) b2bking()->get_user_group( $user_id );
        return $group_id === $expected;
    }

    /**
     * Order customer must be logged in and in the Pappou Club B2B group (no guest orders).
     */
    public static function order_customer_in_pappou_club( $order ): bool {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return false;
        }
        return self::user_in_pappou_club( (int) $order->get_user_id() );
    }

    /**
     * Member row must have a linked WP user who is in the Pappou Club group.
     */
    public static function member_row_in_pappou_club( array $member ): bool {
        $uid = isset( $member['user_id'] ) ? (int) $member['user_id'] : 0;
        return self::user_in_pappou_club( $uid );
    }

    /**
     * Resolve epc_members.id to WP user and check group membership.
     */
    public static function member_id_in_pappou_club( int $member_id ): bool {
        global $wpdb;
        $uid = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}epc_members WHERE id = %d",
                $member_id
            )
        );
        return self::user_in_pappou_club( $uid );
    }

    /**
     * Assign the configured Pappou Club B2B group to a WordPress user (used after registration / admin add).
     */
    public static function assign_pappou_club_group( int $user_id ): bool {
        if ( $user_id < 1 || ! self::is_active() ) {
            return false;
        }
        $gid = self::get_configured_group_id();
        if ( $gid < 1 ) {
            return false;
        }

        if ( ! method_exists( b2bking(), 'update_user_group' ) ) {
            return false;
        }

        b2bking()->update_user_group( $user_id, $gid );

        if ( method_exists( b2bking(), 'clear_caches_transients' ) ) {
            b2bking()->clear_caches_transients();
        }

        return true;
    }
}
