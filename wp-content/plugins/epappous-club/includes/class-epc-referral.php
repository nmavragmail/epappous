<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Referral System
 *
 * How it works:
 * 1. Every member gets a unique referral code (e.g. PAPPOU-A3X9).
 * 2. They share the code or a link (?ref=CODE) with friends.
 * 3. When someone visits with that link, the code is stored in a cookie.
 * 4. Tracking happens at two possible events:
 *    a) Membership sign-up  — the new member is linked to the referrer.
 *    b) First purchase       — the referrer earns reward when the referred user completes an order.
 * 5. Rewards can be points, a percentage discount, or a fixed discount.
 * 6. Both the referrer AND the referred person can receive rewards (configurable).
 */
class EPC_Referral {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'capture_referral_cookie' ] );

        // WooCommerce hooks
        add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ] );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'attach_referral_to_order' ] );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'attach_referral_to_order_from_blocks' ] );

        // Membership sign-up hook (internal)
        add_action( 'epc_member_registered', [ $this, 'on_member_registered' ], 10, 2 );
    }

    /**
     * Generate a unique referral code for a new member.
     */
    public static function generate_code(): string {
        $prefix = EPC_Settings::get( 'epc_referral_code_prefix' );
        $code   = strtoupper( $prefix . '-' . wp_generate_password( 4, false ) );

        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE referral_code = %s",
                $code
            )
        );

        return $exists ? self::generate_code() : $code;
    }

    /**
     * Capture ?ref=CODE from URL and store in a cookie.
     */
    public function capture_referral_cookie() {
        if ( ! isset( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $code = sanitize_text_field( wp_unslash( $_GET['ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( empty( $code ) ) {
            return;
        }

        $days = (int) EPC_Settings::get( 'epc_referral_cookie_days' );
        if ( $days < 1 ) {
            $days = 30;
        }

        setcookie( 'epc_ref', $code, time() + ( DAY_IN_SECONDS * $days ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    }

    /**
     * When a new member registers, record the referral if a cookie exists.
     */
    public function on_member_registered( int $member_id, array $data ) {
        if ( EPC_Settings::get( 'epc_referral_enabled' ) !== '1' ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_referral_track_membership' ) !== '1' ) {
            return;
        }

        $ref_code = $_COOKIE['epc_ref'] ?? '';
        if ( empty( $ref_code ) ) {
            return;
        }

        global $wpdb;

        $referrer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}epc_members WHERE referral_code = %s AND status = 'active'",
                sanitize_text_field( $ref_code )
            )
        );

        if ( ! $referrer || (int) $referrer->id === $member_id ) {
            return;
        }

        if ( ! $this->can_refer( $referrer->id ) ) {
            return;
        }

        $reward_referrer = (int) EPC_Settings::get( 'epc_referral_reward_referrer' );
        $reward_referred = (int) EPC_Settings::get( 'epc_referral_reward_referred' );
        $reward_type     = EPC_Settings::get( 'epc_referral_reward_type' );

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_referrals",
            [
                'referrer_member_id' => $referrer->id,
                'referred_member_id' => $member_id,
                'referred_email'     => $data['email'] ?? '',
                'type'               => 'membership',
                'reward_points'      => $reward_referrer,
                'reward_type'        => $reward_type,
                'status'             => 'completed',
                'completed_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ePappous Club: Failed to insert membership referral for referrer #' . $referrer->id );
            }
            return;
        }

        // If no purchase is required, give rewards immediately
        if ( EPC_Settings::get( 'epc_referral_require_purchase' ) !== '1' ) {
            $this->give_points( $referrer->id, $reward_referrer, 'referral_bonus_referrer', $member_id );
            $this->give_points( $member_id, $reward_referred, 'referral_bonus_referred', $referrer->id );

            $wpdb->update(
                "{$wpdb->prefix}epc_referrals",
                [ 'reward_given' => 1 ],
                [ 'referrer_member_id' => $referrer->id, 'referred_member_id' => $member_id ],
                [ '%d' ],
                [ '%d', '%d' ]
            );

            do_action( 'epc_points_changed', (int) $referrer->id );
            do_action( 'epc_points_changed', $member_id );
        }

        // Link the member record
        $wpdb->update(
            "{$wpdb->prefix}epc_members",
            [ 'referred_by' => $referrer->id ],
            [ 'id' => $member_id ],
            [ '%d' ],
            [ '%d' ]
        );

        do_action( 'epc_referral_completed', $referrer->id, $member_id, 'membership' );
    }

    /**
     * Block-checkout variant: receives WC_Order (1 arg), delegates to attach_referral_to_order().
     */
    public function attach_referral_to_order_from_blocks( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $this->attach_referral_to_order( $order->get_id() );
    }

    /**
     * Attach referral code to the order meta at checkout.
     */
    public function attach_referral_to_order( $order_id ) {
        $ref_code = $_COOKIE['epc_ref'] ?? '';
        if ( empty( $ref_code ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $order->update_meta_data( '_epc_referral_code', sanitize_text_field( $ref_code ) );
        $order->save();
    }

    /**
     * When an order is completed, process the referral reward for purchases.
     */
    public function on_order_completed( $order_id ) {
        if ( EPC_Settings::get( 'epc_referral_enabled' ) !== '1' ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_referral_track_purchase' ) !== '1' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $ref_code = $order->get_meta( '_epc_referral_code', true );
        if ( empty( $ref_code ) ) {
            return;
        }

        // Already processed
        if ( $order->get_meta( '_epc_referral_processed', true ) ) {
            return;
        }

        $min_order = (float) EPC_Settings::get( 'epc_referral_min_order' );
        if ( $min_order > 0 && (float) $order->get_total() < $min_order ) {
            return;
        }

        global $wpdb;

        $referrer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}epc_members WHERE referral_code = %s AND status = 'active'",
                $ref_code
            )
        );
        if ( ! $referrer ) {
            return;
        }

        $buyer_email = $order->get_billing_email();
        $referred    = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}epc_members WHERE email = %s",
                $buyer_email
            )
        );

        if ( ! $referred || (int) $referred->id === (int) $referrer->id ) {
            return;
        }

        if ( ! $this->can_refer( $referrer->id ) ) {
            return;
        }

        $reward_referrer = (int) EPC_Settings::get( 'epc_referral_reward_referrer' );
        $reward_referred = (int) EPC_Settings::get( 'epc_referral_reward_referred' );
        $reward_type     = EPC_Settings::get( 'epc_referral_reward_type' );

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_referrals",
            [
                'referrer_member_id' => $referrer->id,
                'referred_member_id' => $referred->id,
                'referred_email'     => $buyer_email,
                'type'               => 'purchase',
                'order_id'           => $order_id,
                'reward_points'      => $reward_referrer,
                'reward_type'        => $reward_type,
                'reward_given'       => 1,
                'status'             => 'completed',
                'completed_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' ]
        );
        if ( false === $inserted ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ePappous Club: Failed to insert purchase referral for order #' . $order_id );
            }
            return;
        }

        $this->give_points( $referrer->id, $reward_referrer, 'referral_purchase_referrer', $order_id );
        $this->give_points( $referred->id, $reward_referred, 'referral_purchase_referred', $order_id );

        $order->update_meta_data( '_epc_referral_processed', '1' );
        $order->save();

        do_action( 'epc_referral_completed', $referrer->id, $referred->id, 'purchase' );
        do_action( 'epc_points_changed', (int) $referrer->id );
        do_action( 'epc_points_changed', (int) $referred->id );

        // Fulfill pending membership referrals that required a purchase
        $this->fulfill_pending_membership_referral( (int) $referrer->id, (int) $referred->id );
    }

    /**
     * If a membership referral was recorded with require_purchase ON,
     * the reward was deferred. Now that the referred member has purchased,
     * give the deferred rewards.
     */
    private function fulfill_pending_membership_referral( int $referrer_id, int $referred_id ) {
        if ( EPC_Settings::get( 'epc_referral_require_purchase' ) !== '1' ) {
            return;
        }

        global $wpdb;
        $pending = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}epc_referrals
                 WHERE referrer_member_id = %d
                   AND referred_member_id = %d
                   AND type = 'membership'
                   AND reward_given = 0
                 LIMIT 1",
                $referrer_id,
                $referred_id
            )
        );

        if ( ! $pending ) {
            return;
        }

        $reward_referrer = (int) EPC_Settings::get( 'epc_referral_reward_referrer' );
        $reward_referred = (int) EPC_Settings::get( 'epc_referral_reward_referred' );

        $this->give_points( $referrer_id, $reward_referrer, 'referral_bonus_referrer', $referred_id );
        $this->give_points( $referred_id, $reward_referred, 'referral_bonus_referred', $referrer_id );

        $wpdb->update(
            "{$wpdb->prefix}epc_referrals",
            [ 'reward_given' => 1, 'completed_at' => current_time( 'mysql' ) ],
            [ 'id' => $pending->id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        do_action( 'epc_points_changed', $referrer_id );
        do_action( 'epc_points_changed', $referred_id );
    }

    /**
     * Check if a referrer hasn't exceeded the max referral limit.
     */
    private function can_refer( int $referrer_id ): bool {
        $max = (int) EPC_Settings::get( 'epc_referral_max_referrals' );
        if ( $max < 1 ) {
            return true; // unlimited
        }

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE referrer_member_id = %d AND status = 'completed'",
                $referrer_id
            )
        );

        return $count < $max;
    }

    /**
     * Award points to a member and log the transaction.
     */
    private function give_points( int $member_id, int $points, string $reason, $reference_id = null ): bool {
        if ( $points < 1 ) {
            return false;
        }

        if ( ! EPC_B2BKing::member_id_in_pappou_club( $member_id ) ) {
            return false;
        }

        global $wpdb;

        $ref_log_id = null !== $reference_id ? (int) $reference_id : 0;

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                $points,
                $member_id
            )
        );
        if ( 1 !== $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => $points,
                'reason'         => $reason,
                'reference_type' => 'referral',
                'reference_id'   => $ref_log_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $wpdb->query( 'COMMIT' );
        return true;
    }

    /**
     * Get referral stats for a member.
     */
    public static function get_stats( int $member_id ): array {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE referrer_member_id = %d",
                $member_id
            )
        );

        $completed = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE referrer_member_id = %d AND status = 'completed'",
                $member_id
            )
        );

        $points_earned = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(reward_points), 0) FROM {$wpdb->prefix}epc_referrals WHERE referrer_member_id = %d AND reward_given = 1",
                $member_id
            )
        );

        return [
            'total'         => $total,
            'completed'     => $completed,
            'pending'       => $total - $completed,
            'points_earned' => $points_earned,
        ];
    }
}
