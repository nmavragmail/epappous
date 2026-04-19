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

        // WooCommerce hooks. Both processing and completed are listened to so
        // referrals work in shops that never move orders to "completed".
        add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_paid' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_paid' ] );
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
     * Cookie lifetime (in days) for referral tracking. Backed by setting,
     * with a sane fallback.
     */
    public static function cookie_days(): int {
        $days = (int) EPC_Settings::get( 'epc_referral_cookie_days' );
        return $days > 0 ? $days : 30;
    }

    /**
     * Capture ?ref=CODE from URL and store in a cookie.
     *
     * Also records a row in {$wpdb->prefix}epc_referral_clicks so that the
     * admin can see pending leads (visited via referral link, not yet
     * converted) along with a "Λήγει σε" countdown.
     */
    public function capture_referral_cookie() {
        if ( ! isset( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $code = sanitize_text_field( wp_unslash( $_GET['ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( empty( $code ) ) {
            return;
        }

        $days    = self::cookie_days();
        $expires = time() + ( DAY_IN_SECONDS * $days );

        setcookie( 'epc_ref', $code, $expires, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        $_COOKIE['epc_ref'] = $code;

        $this->record_referral_click( $code, $expires );
    }

    /**
     * Persist (or update) a referral click row and ensure the visitor has a
     * stable cookie token so we can mark them as converted later.
     */
    private function record_referral_click( string $code, int $expires_at ): void {
        // Skip obvious non-visitors so we don't pollute the log.
        if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $ua = strtolower( (string) $_SERVER['HTTP_USER_AGENT'] );
            if ( preg_match( '/(bot|crawler|spider|slurp|facebookexternalhit|preview|wget|curl)/', $ua ) ) {
                return;
            }
        }

        global $wpdb;

        $referrer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}epc_members WHERE referral_code = %s AND status = 'active' LIMIT 1",
                $code
            )
        );
        if ( ! $referrer ) {
            return;
        }
        $referrer_id = (int) $referrer->id;

        $token = isset( $_COOKIE['epc_ref_click'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['epc_ref_click'] ) ) : '';
        $row   = null;
        if ( $token ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, referrer_member_id FROM {$wpdb->prefix}epc_referral_clicks WHERE cookie_token = %s LIMIT 1",
                    $token
                )
            );
        }

        if ( $row && (int) $row->referrer_member_id === $referrer_id ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_referral_clicks
                       SET click_count = click_count + 1,
                           last_clicked_at = %s
                     WHERE id = %d",
                    current_time( 'mysql' ),
                    (int) $row->id
                )
            );
            return;
        }

        // Either no cookie, expired/unknown token, or token belongs to a
        // different referrer — start a fresh row.
        $token    = wp_generate_password( 32, false, false );
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_referral_clicks",
            [
                'referrer_member_id' => $referrer_id,
                'ref_code'           => $code,
                'cookie_token'       => $token,
                'click_count'        => 1,
                'first_clicked_at'   => current_time( 'mysql' ),
                'last_clicked_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return;
        }

        setcookie( 'epc_ref_click', $token, $expires_at, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        $_COOKIE['epc_ref_click'] = $token;
    }

    /**
     * When a new member registers, mark the matching referral click as
     * converted (membership step). Rewards are granted only once BOTH
     * membership and purchase have happened.
     */
    public function on_member_registered( int $member_id, array $data ) {
        if ( EPC_Settings::get( 'epc_referral_enabled' ) !== '1' ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_referral_track_membership' ) !== '1' ) {
            return;
        }

        $click = $this->find_click_for_new_member( $member_id, $data );
        if ( ! $click ) {
            return;
        }

        // Self-referral guard.
        if ( (int) $click->referrer_member_id === $member_id ) {
            return;
        }

        if ( ! $this->can_refer( (int) $click->referrer_member_id ) ) {
            return;
        }

        global $wpdb;

        if ( empty( $click->converted_member_id ) ) {
            $wpdb->update(
                "{$wpdb->prefix}epc_referral_clicks",
                [
                    'converted_member_id' => $member_id,
                    'converted_at'        => current_time( 'mysql' ),
                    'referred_email'      => $data['email'] ?? $click->referred_email,
                ],
                [ 'id' => (int) $click->id ],
                [ '%d', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $wpdb->update(
            "{$wpdb->prefix}epc_members",
            [ 'referred_by' => (int) $click->referrer_member_id ],
            [ 'id' => $member_id ],
            [ '%d' ],
            [ '%d' ]
        );

        do_action( 'epc_referral_membership_recorded', (int) $click->referrer_member_id, $member_id );

        // If a purchase already happened on this click, complete the referral.
        $this->grant_rewards_if_complete( (int) $click->id );
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
     * Attach referral code + click token to the order meta at checkout so the
     * referral can be reconciled later regardless of cookie state.
     */
    public function attach_referral_to_order( $order_id ) {
        $ref_code     = isset( $_COOKIE['epc_ref'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['epc_ref'] ) ) : '';
        $click_token  = isset( $_COOKIE['epc_ref_click'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['epc_ref_click'] ) ) : '';

        if ( empty( $ref_code ) && empty( $click_token ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( ! empty( $ref_code ) ) {
            $order->update_meta_data( '_epc_referral_code', $ref_code );
        }
        if ( ! empty( $click_token ) ) {
            $order->update_meta_data( '_epc_referral_click_token', $click_token );
        }
        $order->save();
    }

    /**
     * When a referred order moves to processing/completed, record the
     * purchase against the matching click row. Rewards fire only once the
     * referred buyer is also a Pappou Club member.
     */
    public function on_order_paid( $order_id ) {
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
        if ( $order->get_meta( '_epc_referral_processed', true ) ) {
            return;
        }

        $token    = (string) $order->get_meta( '_epc_referral_click_token', true );
        $ref_code = (string) $order->get_meta( '_epc_referral_code', true );
        if ( empty( $token ) && empty( $ref_code ) ) {
            return;
        }

        $click = $this->find_click_for_order( $order, $token, $ref_code );
        if ( ! $click ) {
            return;
        }

        // Self-referral guard.
        $buyer_email = $order->get_billing_email();
        $referrer_id = (int) $click->referrer_member_id;
        $referred_member = $this->get_member_by_email_or_user( $buyer_email, (int) $order->get_user_id() );
        if ( $referred_member && (int) $referred_member->id === $referrer_id ) {
            $order->update_meta_data( '_epc_referral_processed', '1' );
            $order->save();
            return;
        }

        $min_order = (float) EPC_Settings::get( 'epc_referral_min_order' );
        if ( $min_order > 0 && (float) $order->get_total() < $min_order ) {
            $order->update_meta_data( '_epc_referral_processed', '1' );
            $order->save();
            return;
        }

        if ( ! $this->can_refer( $referrer_id ) ) {
            $order->update_meta_data( '_epc_referral_processed', '1' );
            $order->save();
            return;
        }

        global $wpdb;

        $update = [
            'purchased_order_id' => (int) $order_id,
            'purchased_at'       => current_time( 'mysql' ),
            'purchase_total'     => (float) $order->get_total(),
            'referred_email'     => $buyer_email ?: $click->referred_email,
        ];
        $formats = [ '%d', '%s', '%f', '%s' ];

        if ( $referred_member && empty( $click->converted_member_id ) ) {
            $update['converted_member_id'] = (int) $referred_member->id;
            $update['converted_at']        = current_time( 'mysql' );
            $formats[]                     = '%d';
            $formats[]                     = '%s';
        }

        $wpdb->update(
            "{$wpdb->prefix}epc_referral_clicks",
            $update,
            [ 'id' => (int) $click->id ],
            $formats,
            [ '%d' ]
        );

        $order->update_meta_data( '_epc_referral_processed', '1' );
        $order->save();

        do_action( 'epc_referral_purchase_recorded', $referrer_id, (int) $order_id );

        $this->grant_rewards_if_complete( (int) $click->id );
    }

    /**
     * Locate the EPC member row for an order buyer (by user_id first, then email).
     */
    private function get_member_by_email_or_user( string $email, int $user_id ) {
        global $wpdb;
        if ( $user_id > 0 ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_members WHERE user_id = %d LIMIT 1",
                    $user_id
                )
            );
            if ( $row ) {
                return $row;
            }
        }
        if ( $email ) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_members WHERE email = %s LIMIT 1",
                    $email
                )
            );
        }
        return null;
    }

    /**
     * Find the click row that should be associated with this paid order.
     */
    private function find_click_for_order( \WC_Order $order, string $token, string $ref_code ) {
        global $wpdb;

        if ( $token ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks WHERE cookie_token = %s LIMIT 1",
                    $token
                )
            );
            if ( $row ) {
                return $row;
            }
        }

        if ( $ref_code ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                      WHERE ref_code = %s AND rewarded_at IS NULL
                      ORDER BY first_clicked_at DESC LIMIT 1",
                    $ref_code
                )
            );
            if ( $row ) {
                return $row;
            }

            // No click recorded — synthesise a row so the order is still tracked.
            $referrer = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}epc_members WHERE referral_code = %s AND status = 'active' LIMIT 1",
                    $ref_code
                )
            );
            if ( ! $referrer ) {
                return null;
            }

            $synth_token = 'order-' . (int) $order->get_id();
            $wpdb->insert(
                "{$wpdb->prefix}epc_referral_clicks",
                [
                    'referrer_member_id' => (int) $referrer->id,
                    'ref_code'           => $ref_code,
                    'cookie_token'       => $synth_token,
                    'click_count'        => 0,
                    'first_clicked_at'   => current_time( 'mysql' ),
                    'last_clicked_at'    => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%d', '%s', '%s' ]
            );

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks WHERE cookie_token = %s LIMIT 1",
                    $synth_token
                )
            );
        }

        return null;
    }

    /**
     * Look up the click row that should be tied to a freshly registered member.
     */
    private function find_click_for_new_member( int $member_id, array $data ) {
        global $wpdb;

        $token = isset( $_COOKIE['epc_ref_click'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['epc_ref_click'] ) ) : '';
        if ( $token ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks WHERE cookie_token = %s LIMIT 1",
                    $token
                )
            );
            if ( $row ) {
                return $row;
            }
        }

        $code = isset( $_COOKIE['epc_ref'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['epc_ref'] ) ) : '';
        if ( $code ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                      WHERE ref_code = %s
                        AND converted_member_id IS NULL
                        AND rewarded_at IS NULL
                      ORDER BY first_clicked_at DESC LIMIT 1",
                    $code
                )
            );
            if ( $row ) {
                return $row;
            }
        }

        // Fallback: a "purchase first" click row (created from order meta) that
        // shares the buyer email and is still un-converted.
        $email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
        if ( $email ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                      WHERE referred_email = %s
                        AND converted_member_id IS NULL
                      ORDER BY first_clicked_at DESC LIMIT 1",
                    $email
                )
            );
            if ( $row ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * If a click row has both a converted member and a recorded purchase,
     * grant rewards (idempotently) and write a row in epc_referrals.
     */
    private function grant_rewards_if_complete( int $click_id ): void {
        global $wpdb;

        $click = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_referral_clicks WHERE id = %d LIMIT 1",
                $click_id
            )
        );
        if ( ! $click ) {
            return;
        }
        if ( ! empty( $click->rewarded_at ) ) {
            return;
        }
        if ( empty( $click->converted_member_id ) || empty( $click->purchased_order_id ) ) {
            return;
        }

        // Business rule: ανταμοιβή μόνο όταν και τα δύο "tracks" είναι ενεργά στη ρύθμιση.
        if ( EPC_Settings::get( 'epc_referral_track_membership' ) !== '1' || EPC_Settings::get( 'epc_referral_track_purchase' ) !== '1' ) {
            return;
        }

        $reward_referrer = (int) EPC_Settings::get( 'epc_referral_reward_referrer' );
        $reward_referred = (int) EPC_Settings::get( 'epc_referral_reward_referred' );
        $reward_type     = EPC_Settings::get( 'epc_referral_reward_type' );
        $order_ref       = (int) $click->purchased_order_id;
        $ref_id          = (int) $click->referrer_member_id;
        $conv_id         = (int) $click->converted_member_id;

        // Both members must be in the Pappou Club B2B group before any mutation.
        if ( ! EPC_B2BKing::member_id_in_pappou_club( $ref_id ) || ! EPC_B2BKing::member_id_in_pappou_club( $conv_id ) ) {
            return;
        }

        if ( $reward_referrer < 1 && $reward_referred < 1 ) {
            return;
        }

        $wpdb->query( 'START TRANSACTION' );

        $ok = true;
        if ( $reward_referrer > 0 ) {
            $u1 = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                    $reward_referrer,
                    $ref_id
                )
            );
            if ( 1 !== (int) $u1 ) {
                $ok = false;
            }
            if ( $ok ) {
                $ins = $wpdb->insert(
                    "{$wpdb->prefix}epc_points_log",
                    [
                        'member_id'      => $ref_id,
                        'points'         => $reward_referrer,
                        'reason'         => 'referral_purchase_referrer',
                        'reference_type' => 'referral',
                        'reference_id'   => $order_ref,
                    ],
                    [ '%d', '%d', '%s', '%s', '%d' ]
                );
                if ( false === $ins ) {
                    $ok = false;
                }
            }
        }

        if ( $ok && $reward_referred > 0 ) {
            $u2 = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                    $reward_referred,
                    $conv_id
                )
            );
            if ( 1 !== (int) $u2 ) {
                $ok = false;
            }
            if ( $ok ) {
                $ins2 = $wpdb->insert(
                    "{$wpdb->prefix}epc_points_log",
                    [
                        'member_id'      => $conv_id,
                        'points'         => $reward_referred,
                        'reason'         => 'referral_purchase_referred',
                        'reference_type' => 'referral',
                        'reference_id'   => $order_ref,
                    ],
                    [ '%d', '%d', '%s', '%s', '%d' ]
                );
                if ( false === $ins2 ) {
                    $ok = false;
                }
            }
        }

        if ( $ok ) {
            $upd = $wpdb->update(
                "{$wpdb->prefix}epc_referral_clicks",
                [ 'rewarded_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $click->id ],
                [ '%s' ],
                [ '%d' ]
            );
            if ( false === $upd || (int) $upd < 1 ) {
                $ok = false;
            }
        }

        if ( $ok ) {
            $ins_ref = $wpdb->insert(
                "{$wpdb->prefix}epc_referrals",
                [
                    'referrer_member_id' => $ref_id,
                    'referred_member_id' => $conv_id,
                    'referred_email'     => (string) $click->referred_email,
                    'type'               => 'purchase',
                    'order_id'           => $order_ref,
                    'reward_points'      => $reward_referrer,
                    'reward_type'        => $reward_type,
                    'reward_given'       => 1,
                    'status'             => 'completed',
                    'completed_at'       => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' ]
            );
            if ( false === $ins_ref ) {
                $ok = false;
            }
        }

        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ePappous Club: referral reward transaction failed for click #' . (int) $click->id );
            }
            return;
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_referral_completed', $ref_id, $conv_id, 'full' );
        do_action( 'epc_points_changed', $ref_id );
        do_action( 'epc_points_changed', $conv_id );
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
