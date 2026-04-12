<?php
defined( 'ABSPATH' ) || exit;

/**
 * Points Manager — ledger CRUD, balance management, atomic transactions.
 *
 * Every point change goes through this class so we always have a full
 * audit trail and the cached balance stays in sync.
 */
class TwoNet_Points_Manager {

    private static $instance = null;

    const BALANCE_META = '_2net_loyalty_balance';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /* ------------------------------------------------------------------
     * Balance
     * ----------------------------------------------------------------*/

    /**
     * Get cached balance (fast path). Falls back to recalculation.
     */
    public static function get_balance( int $user_id ): int {
        $balance = get_user_meta( $user_id, self::BALANCE_META, true );

        if ( '' === $balance ) {
            $balance = self::recalculate_balance( $user_id );
        }

        return (int) $balance;
    }

    /**
     * Recalculate balance from the ledger (source of truth).
     */
    public static function recalculate_balance( int $user_id ): int {
        global $wpdb;
        $table = TwoNet_Loyalty_Core::log_table();

        $balance = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM {$table} WHERE user_id = %d",
            $user_id
        ) );

        update_user_meta( $user_id, self::BALANCE_META, $balance );
        return $balance;
    }

    /* ------------------------------------------------------------------
     * Add / deduct points (atomic)
     * ----------------------------------------------------------------*/

    /**
     * Add points to a user's account.
     *
     * @param int    $user_id     WP user ID.
     * @param int    $points      Positive integer.
     * @param string $type        Transaction type (order, referral, birthday, registration, manual, etc.).
     * @param int    $ref_id      Optional reference (order ID, coupon ID, etc.).
     * @param string $description Human-readable note.
     * @return int|false New balance on success, false on failure.
     */
    public static function add_points( int $user_id, int $points, string $type = 'manual', int $ref_id = 0, string $description = '' ) {
        if ( $points <= 0 || ! get_userdata( $user_id ) ) {
            return false;
        }
        return self::record_transaction( $user_id, $points, $type, $ref_id, $description );
    }

    /**
     * Deduct points from a user's account.
     *
     * @param int    $user_id
     * @param int    $points      Positive integer (will be stored as negative).
     * @param string $type
     * @param int    $ref_id
     * @param string $description
     * @return int|false New balance on success, false on failure or insufficient balance.
     */
    public static function deduct_points( int $user_id, int $points, string $type = 'redeem', int $ref_id = 0, string $description = '' ) {
        if ( $points <= 0 || ! get_userdata( $user_id ) ) {
            return false;
        }

        $balance = self::get_balance( $user_id );
        if ( $balance < $points ) {
            return false;
        }

        return self::record_transaction( $user_id, -$points, $type, $ref_id, $description );
    }

    /* ------------------------------------------------------------------
     * Ledger record (private, atomic)
     * ----------------------------------------------------------------*/

    private static function record_transaction( int $user_id, int $points, string $type, int $ref_id, string $description ) {
        global $wpdb;

        $table = TwoNet_Loyalty_Core::log_table();

        $wpdb->query( 'START TRANSACTION' );

        try {
            $current_balance = self::recalculate_balance( $user_id );
            $new_balance     = $current_balance + $points;

            if ( $new_balance < 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return false;
            }

            $inserted = $wpdb->insert( $table, [
                'user_id'     => $user_id,
                'points'      => $points,
                'type'        => sanitize_key( $type ),
                'ref_id'      => $ref_id ?: null,
                'description' => sanitize_text_field( $description ),
                'balance'     => $new_balance,
                'created_at'  => current_time( 'mysql' ),
            ], [ '%d', '%d', '%s', '%d', '%s', '%d', '%s' ] );

            if ( false === $inserted ) {
                $wpdb->query( 'ROLLBACK' );
                return false;
            }

            update_user_meta( $user_id, self::BALANCE_META, $new_balance );

            $wpdb->query( 'COMMIT' );

            do_action( '2net_loyalty_points_changed', $user_id, $points, $type, $ref_id, $new_balance );

            return $new_balance;

        } catch ( \Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
    }

    /* ------------------------------------------------------------------
     * History / Log queries
     * ----------------------------------------------------------------*/

    /**
     * Get transaction history for a user.
     *
     * @param int $user_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function get_history( int $user_id, int $limit = 20, int $offset = 0 ): array {
        global $wpdb;
        $table = TwoNet_Loyalty_Core::log_table();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ) );
    }

    /**
     * Count total transactions for a user (for pagination).
     */
    public static function count_history( int $user_id ): int {
        global $wpdb;
        $table = TwoNet_Loyalty_Core::log_table();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ) );
    }

    /**
     * Check if a specific award has already been given.
     * Useful for idempotency (e.g. birthday, order, registration).
     */
    public static function has_transaction( int $user_id, string $type, int $ref_id = 0 ): bool {
        global $wpdb;
        $table = TwoNet_Loyalty_Core::log_table();

        if ( $ref_id ) {
            return (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND type = %s AND ref_id = %d",
                $user_id,
                $type,
                $ref_id
            ) );
        }

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND type = %s",
            $user_id,
            $type
        ) );
    }

    /**
     * Type labels for display.
     */
    public static function type_label( string $type ): string {
        $labels = [
            'order'        => __( 'Πόντοι αγοράς', '2net-loyalty' ),
            'redeem'       => __( 'Εξαργύρωση πόντων', '2net-loyalty' ),
            'gift'         => __( 'Εξαργύρωση για δώρο', '2net-loyalty' ),
            'coupon'       => __( 'Αγορά κουπονιού', '2net-loyalty' ),
            'registration' => __( 'Δώρο εγγραφής', '2net-loyalty' ),
            'referral'     => __( 'Πόντοι σύστασης', '2net-loyalty' ),
            'birthday'     => __( 'Πόντοι γενεθλίων', '2net-loyalty' ),
            'manual'       => __( 'Χειροκίνητη προσαρμογή', '2net-loyalty' ),
            'refund'       => __( 'Επιστροφή πόντων', '2net-loyalty' ),
        ];
        return $labels[ $type ] ?? $type;
    }
}
