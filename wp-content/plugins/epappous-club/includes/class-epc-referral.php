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
        add_action( 'admin_post_epc_referral_reconcile_orders', [ $this, 'handle_admin_reconcile_orders' ] );
        add_action( 'admin_post_epc_referral_reconcile_click', [ $this, 'handle_admin_reconcile_click' ] );
        add_action( 'admin_post_epc_referral_attach_purchase_order', [ $this, 'handle_admin_attach_purchase_order' ] );

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
     * Admin action: retrospective reconciliation for past orders.
     */
    public function handle_admin_reconcile_orders(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Δεν επιτρέπεται η ενέργεια.', 'epappous-club' ) );
        }

        check_admin_referer( 'epc_referral_reconcile_orders', 'epc_referral_reconcile_nonce' );

        $limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 250; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $report = $this->reconcile_referrals_for_past_orders( $limit );

        $summary = [];
        foreach ( (array) $report['entries'] as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $summary[] = sprintf(
                '#%d — %s',
                (int) ( $entry['order_id'] ?? 0 ),
                (string) ( $entry['message'] ?? 'unknown' )
            );
        }

        set_transient(
            'epc_referral_reconcile_last',
            [
                'ran_at'   => (int) current_time( 'timestamp' ),
                'checked'  => (int) ( $report['processed'] ?? 0 ),
                'repaired' => (int) ( $report['updated'] ?? 0 ),
                'rewarded' => (int) ( $report['rewarded'] ?? 0 ),
                'skipped'  => (int) ( $report['skipped'] ?? 0 ),
                'errors'   => (int) ( $report['errors'] ?? 0 ),
                'summary'  => $summary,
            ],
            HOUR_IN_SECONDS * 24
        );

        wp_safe_redirect( admin_url( 'admin.php?page=epc-referrals' ) );
        exit;
    }

    /**
     * Admin action: reconcile a single click row by referred email.
     */
    public function handle_admin_reconcile_click(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Δεν επιτρέπεται η ενέργεια.', 'epappous-club' ) );
        }
        check_admin_referer( 'epc_referral_reconcile_click', 'epc_referral_reconcile_click_nonce' );

        $click_id = isset( $_POST['click_id'] ) ? (int) $_POST['click_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $entry    = $this->reconcile_single_click_row( $click_id, 0, 90 );
        $this->store_single_click_admin_result( $entry );

        wp_safe_redirect( admin_url( 'admin.php?page=epc-referrals&focus_click=' . max( 0, $click_id ) ) );
        exit;
    }

    /**
     * Admin action: manually attach an order ID as the referred purchase.
     */
    public function handle_admin_attach_purchase_order(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Δεν επιτρέπεται η ενέργεια.', 'epappous-club' ) );
        }
        check_admin_referer( 'epc_referral_attach_purchase_order', 'epc_referral_attach_purchase_order_nonce' );

        $click_id  = isset( $_POST['click_id'] ) ? (int) $_POST['click_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $order_id  = isset( $_POST['manual_order_id'] ) ? (int) $_POST['manual_order_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $entry     = $this->reconcile_single_click_row( $click_id, $order_id, 90 );
        $this->store_single_click_admin_result( $entry );

        wp_safe_redirect( admin_url( 'admin.php?page=epc-referrals&focus_click=' . max( 0, $click_id ) ) );
        exit;
    }

    /**
     * Persist one-off admin result for per-click actions.
     *
     * @param array<string,mixed> $entry Result payload.
     */
    private function store_single_click_admin_result( array $entry ): void {
        $line = sprintf(
            '#%d / order #%d — %s',
            (int) ( $entry['click_id'] ?? 0 ),
            (int) ( $entry['order_id'] ?? 0 ),
            (string) ( $entry['message'] ?? 'unknown' )
        );

        set_transient(
            'epc_referral_single_click_last',
            [
                'ran_at'  => (int) current_time( 'timestamp' ),
                'summary' => $line,
                'entry'   => $entry,
            ],
            HOUR_IN_SECONDS * 24
        );
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
        $this->process_order_paid_for_referral( (int) $order_id, false );
    }

    /**
     * Retrospective reconciliation for already paid orders (processing/completed).
     *
     * @param int $max_orders Maximum number of orders to inspect in one run.
     * @return array{processed:int,updated:int,rewarded:int,skipped:int,errors:int,entries:array<int,array<string,mixed>>}
     */
    public function reconcile_referrals_for_past_orders( int $max_orders = 300 ): array {
        $max_orders = max( 1, min( 5000, $max_orders ) );

        $report = [
            'processed' => 0,
            'updated'   => 0,
            'rewarded'  => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'entries'   => [],
        ];

        $remaining = $max_orders;
        $page      = 1;
        $per_page  = 100;

        while ( $remaining > 0 ) {
            $batch = min( $per_page, $remaining );
            $orders = wc_get_orders(
                [
                    'status'   => [ 'processing', 'completed' ],
                    'type'     => 'shop_order',
                    'return'   => 'ids',
                    'orderby'  => 'date',
                    'order'    => 'DESC',
                    'paginate' => true,
                    'paged'    => $page,
                    'limit'    => $batch,
                ]
            );

            $ids = ( isset( $orders->orders ) && is_array( $orders->orders ) ) ? $orders->orders : [];
            if ( empty( $ids ) ) {
                break;
            }

            foreach ( $ids as $order_id ) {
                $result = $this->process_order_paid_for_referral( (int) $order_id, true );
                $report['processed']++;
                $report['entries'][] = $result;

                if ( ! empty( $result['error'] ) ) {
                    $report['errors']++;
                } elseif ( ! empty( $result['updated'] ) ) {
                    $report['updated']++;
                    if ( ! empty( $result['rewarded_now'] ) ) {
                        $report['rewarded']++;
                    }
                } else {
                    $report['skipped']++;
                }
            }

            $remaining -= count( $ids );
            $page++;
        }

        // Extra safety pass: bind pending referral clicks by referred_email directly
        // to paid WooCommerce orders, even when order referral meta is missing.
        $email_pass = $this->reconcile_pending_clicks_by_referred_email( $max_orders );
        foreach ( (array) $email_pass['entries'] as $entry ) {
            $report['entries'][] = $entry;
            $report['processed']++;
            if ( ! empty( $entry['error'] ) ) {
                $report['errors']++;
            } elseif ( ! empty( $entry['updated'] ) ) {
                $report['updated']++;
                if ( ! empty( $entry['rewarded_now'] ) ) {
                    $report['rewarded']++;
                }
            } else {
                $report['skipped']++;
            }
        }

        return $report;
    }

    /**
     * Reconcile pending clicks by matching referred_email against paid orders.
     *
     * @param int $max_rows Maximum rows to inspect.
     * @return array{entries:array<int,array<string,mixed>>}
     */
    private function reconcile_pending_clicks_by_referred_email( int $max_rows ): array {
        global $wpdb;

        $max_rows = max( 1, min( 5000, $max_rows ) );
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                 WHERE purchased_order_id IS NULL
                   AND rewarded_at IS NULL
                   AND COALESCE(referred_email, '') <> ''
                 ORDER BY first_clicked_at DESC
                 LIMIT %d",
                $max_rows
            )
        );

        $entries = [];
        foreach ( (array) $rows as $row ) {
            $click_id = (int) ( $row->id ?? 0 );
            $email    = sanitize_email( (string) ( $row->referred_email ?? '' ) );

            $entry = [
                'order_id'      => 0,
                'click_id'      => $click_id,
                'updated'       => false,
                'rewarded_now'  => false,
                'message'       => 'email_match_skipped',
                'error'         => false,
            ];

            if ( $click_id < 1 || $email === '' ) {
                $entry['message'] = 'email_missing';
                $entries[]        = $entry;
                continue;
            }

            $order = $this->find_latest_paid_order_by_email( $email );
            if ( ! $order ) {
                $entry['message'] = 'no_paid_order_for_referred_email';
                $entries[]        = $entry;
                continue;
            }

            $order_id = (int) $order->get_id();
            $entry['order_id'] = $order_id;

            $buyer_member    = $this->get_member_by_email_or_user( $email, (int) $order->get_user_id() );
            $buyer_member_id = $buyer_member ? (int) $buyer_member->id : 0;
            $referrer_id     = (int) ( $row->referrer_member_id ?? 0 );

            if ( $buyer_member_id > 0 && $buyer_member_id === $referrer_id ) {
                $entry['message'] = 'self_referral_guard';
                $entries[]        = $entry;
                continue;
            }

            $min_order       = (float) EPC_Settings::get( 'epc_referral_min_order' );
            $is_below_minimum = ( $min_order > 0 && (float) $order->get_total() < $min_order );

            if ( ! $this->can_refer( $referrer_id ) ) {
                $entry['message'] = 'max_referrals_reached';
                $entries[]        = $entry;
                continue;
            }

            $was_rewarded = ! empty( $row->rewarded_at );
            $update       = [
                'purchased_order_id' => $order_id,
                'purchased_at'       => current_time( 'mysql' ),
                'purchase_total'     => (float) $order->get_total(),
                'referred_email'     => $email,
            ];
            $formats      = [ '%d', '%s', '%f', '%s' ];

            if ( $buyer_member_id > 0 && empty( $row->converted_member_id ) ) {
                $update['converted_member_id'] = $buyer_member_id;
                $update['converted_at']        = current_time( 'mysql' );
                $formats[]                     = '%d';
                $formats[]                     = '%s';
            }

            $updated = $wpdb->update(
                "{$wpdb->prefix}epc_referral_clicks",
                $update,
                [ 'id' => $click_id ],
                $formats,
                [ '%d' ]
            );

            if ( false === $updated ) {
                $entry['message'] = 'db_update_failed';
                $entry['error']   = true;
                $entries[]        = $entry;
                continue;
            }

            $order->update_meta_data( '_epc_referral_processed', '1' );
            $order->update_meta_data( '_epc_referral_reconciled_at', current_time( 'mysql' ) );
            $order->update_meta_data( '_epc_referral_reconcile_result', 'updated_by_referred_email' );
            $order->save();

            do_action( 'epc_referral_purchase_recorded', $referrer_id, $order_id );
            if ( ! $is_below_minimum ) {
                $this->grant_rewards_if_complete( $click_id );
            }

            $fresh_click = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT rewarded_at FROM {$wpdb->prefix}epc_referral_clicks WHERE id = %d LIMIT 1",
                    $click_id
                )
            );
            $is_rewarded_now      = ( $fresh_click && ! empty( $fresh_click->rewarded_at ) );
            $entry['rewarded_now'] = ( ! $was_rewarded && $is_rewarded_now );
            $entry['updated']      = true;
            $entry['message']      = $is_below_minimum
                ? 'updated_purchase_below_min_order'
                : ( $entry['rewarded_now'] ? 'updated_by_referred_email_and_rewarded' : 'updated_by_referred_email' );
            $entries[]             = $entry;
        }

        return [ 'entries' => $entries ];
    }

    /**
     * Reconcile one specific click row.
     *
     * @param int $click_id Click row id.
     * @param int $manual_order_id Optional explicit order id to force-check.
     * @param int $days_back Search window in days.
     * @return array<string,mixed>
     */
    private function reconcile_single_click_row( int $click_id, int $manual_order_id = 0, int $days_back = 90 ): array {
        global $wpdb;

        $entry = [
            'order_id'      => 0,
            'click_id'      => max( 0, $click_id ),
            'updated'       => false,
            'rewarded_now'  => false,
            'message'       => 'click_not_found',
            'error'         => false,
        ];

        if ( $click_id < 1 ) {
            $entry['message'] = 'invalid_click_id';
            $entry['error']   = true;
            return $entry;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_referral_clicks WHERE id = %d LIMIT 1",
                $click_id
            )
        );
        if ( ! $row ) {
            $entry['message'] = 'click_not_found';
            return $entry;
        }

        $email = sanitize_email( (string) ( $row->referred_email ?? '' ) );
        if ( '' === $email ) {
            $entry['message'] = 'referred_email_missing';
            return $entry;
        }

        $order = null;
        if ( $manual_order_id > 0 ) {
            $order = wc_get_order( $manual_order_id );
            if ( ! $order ) {
                $entry['message'] = 'manual_order_not_found';
                return $entry;
            }

            if ( ! in_array( (string) $order->get_status(), [ 'processing', 'completed' ], true ) ) {
                $entry['message'] = 'manual_order_invalid_status';
                return $entry;
            }

            $order_email = sanitize_email( (string) $order->get_billing_email() );
            if ( strtolower( $order_email ) !== strtolower( $email ) ) {
                $entry['message'] = 'manual_order_email_mismatch';
                return $entry;
            }
        } else {
            $order = $this->find_referral_purchase_order_by_email( $email, (string) $row->first_clicked_at, $days_back );
            if ( ! $order ) {
                $entry['message'] = 'no_paid_order_for_referred_email_in_window';
                return $entry;
            }
        }

        $order_id          = (int) $order->get_id();
        $entry['order_id'] = $order_id;

        if ( ! empty( $row->purchased_order_id ) && (int) $row->purchased_order_id > 0 && (int) $row->purchased_order_id !== $order_id ) {
            $entry['message'] = 'click_already_bound_to_other_order';
            return $entry;
        }

        $buyer_member    = $this->get_member_by_email_or_user( $email, (int) $order->get_user_id() );
        $buyer_member_id = $buyer_member ? (int) $buyer_member->id : 0;
        $referrer_id     = (int) ( $row->referrer_member_id ?? 0 );

        if ( $buyer_member_id > 0 && $buyer_member_id === $referrer_id ) {
            $entry['message'] = 'self_referral_guard';
            return $entry;
        }

        $min_order        = (float) EPC_Settings::get( 'epc_referral_min_order' );
        $is_below_minimum = ( $min_order > 0 && (float) $order->get_total() < $min_order );

        if ( ! $this->can_refer( $referrer_id ) ) {
            $entry['message'] = 'max_referrals_reached';
            return $entry;
        }

        $was_rewarded = ! empty( $row->rewarded_at );
        $update       = [
            'purchased_order_id' => $order_id,
            'purchased_at'       => current_time( 'mysql' ),
            'purchase_total'     => (float) $order->get_total(),
            'referred_email'     => $email,
        ];
        $formats      = [ '%d', '%s', '%f', '%s' ];

        if ( $buyer_member_id > 0 && empty( $row->converted_member_id ) ) {
            $update['converted_member_id'] = $buyer_member_id;
            $update['converted_at']        = current_time( 'mysql' );
            $formats[]                     = '%d';
            $formats[]                     = '%s';
        }

        $updated = $wpdb->update(
            "{$wpdb->prefix}epc_referral_clicks",
            $update,
            [ 'id' => $click_id ],
            $formats,
            [ '%d' ]
        );
        if ( false === $updated ) {
            $entry['message'] = 'db_update_failed';
            $entry['error']   = true;
            return $entry;
        }

        $order->update_meta_data( '_epc_referral_processed', '1' );
        $order->update_meta_data( '_epc_referral_reconciled_at', current_time( 'mysql' ) );
        $order->update_meta_data(
            '_epc_referral_reconcile_result',
            $manual_order_id > 0 ? 'updated_by_manual_order' : 'updated_by_referred_email_full_check'
        );
        $order->save();

        do_action( 'epc_referral_purchase_recorded', $referrer_id, $order_id );
        if ( ! $is_below_minimum ) {
            $this->grant_rewards_if_complete( $click_id );
        }

        $fresh_click = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT rewarded_at FROM {$wpdb->prefix}epc_referral_clicks WHERE id = %d LIMIT 1",
                $click_id
            )
        );
        $is_rewarded_now       = ( $fresh_click && ! empty( $fresh_click->rewarded_at ) );
        $entry['rewarded_now'] = ( ! $was_rewarded && $is_rewarded_now );
        $entry['updated']      = true;
        if ( $is_below_minimum ) {
            $entry['message'] = $manual_order_id > 0 ? 'updated_by_manual_order_below_min_order' : 'updated_by_referred_email_full_check_below_min_order';
        } else {
            $entry['message'] = $manual_order_id > 0
                ? ( $entry['rewarded_now'] ? 'updated_by_manual_order_and_rewarded' : 'updated_by_manual_order' )
                : ( $entry['rewarded_now'] ? 'updated_by_referred_email_full_check_and_rewarded' : 'updated_by_referred_email_full_check' );
        }

        return $entry;
    }

    /**
     * Find order by billing email in last N days.
     * Prefer order created after click timestamp, fallback to any in window.
     */
    private function find_referral_purchase_order_by_email( string $email, string $clicked_at = '', int $days_back = 90 ) {
        $email = sanitize_email( $email );
        if ( '' === $email ) {
            return null;
        }

        $days_back = max( 1, min( 365, $days_back ) );
        $after_ts  = (int) strtotime( '-' . $days_back . ' days', current_time( 'timestamp' ) );
        $date_from = gmdate( 'Y-m-d H:i:s', $after_ts );

        $orders = wc_get_orders(
            [
                'status'        => [ 'processing', 'completed' ],
                'type'          => 'shop_order',
                'billing_email' => $email,
                'orderby'       => 'date',
                'order'         => 'ASC',
                'limit'         => 200,
                'date_created'  => '>=' . $date_from,
            ]
        );
        if ( ! is_array( $orders ) || empty( $orders ) ) {
            return null;
        }

        $clicked_ts = $clicked_at ? (int) strtotime( $clicked_at ) : 0;
        if ( $clicked_ts > 0 ) {
            foreach ( $orders as $order ) {
                if ( ! $order instanceof \WC_Order ) {
                    continue;
                }
                $created = $order->get_date_created();
                $created_ts = $created ? $created->getTimestamp() : 0;
                if ( $created_ts >= $clicked_ts ) {
                    return $order;
                }
            }
        }

        return ( $orders[0] instanceof \WC_Order ) ? $orders[0] : null;
    }

    /**
     * Find latest paid order (processing/completed) by billing email.
     */
    private function find_latest_paid_order_by_email( string $email ) {
        $email = sanitize_email( $email );
        if ( '' === $email ) {
            return null;
        }

        $orders = wc_get_orders(
            [
                'status'        => [ 'processing', 'completed' ],
                'type'          => 'shop_order',
                'billing_email' => $email,
                'orderby'       => 'date',
                'order'         => 'DESC',
                'limit'         => 1,
            ]
        );

        if ( is_array( $orders ) && ! empty( $orders[0] ) && $orders[0] instanceof \WC_Order ) {
            return $orders[0];
        }

        return null;
    }

    /**
     * Shared referral processing logic for paid orders.
     *
     * @param int  $order_id Order ID.
     * @param bool $force_reconcile Whether this run is retrospective reconciliation.
     * @return array<string,mixed>
     */
    private function process_order_paid_for_referral( int $order_id, bool $force_reconcile ): array {
        $result = [
            'order_id'      => $order_id,
            'updated'       => false,
            'rewarded_now'  => false,
            'message'       => '',
            'error'         => false,
        ];

        if ( EPC_Settings::get( 'epc_referral_enabled' ) !== '1' ) {
            $result['message'] = 'referral_disabled';
            return $result;
        }
        if ( EPC_Settings::get( 'epc_referral_track_purchase' ) !== '1' ) {
            $result['message'] = 'purchase_tracking_disabled';
            return $result;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $result['message'] = 'order_not_found';
            $result['error']   = true;
            return $result;
        }
        if ( ! $force_reconcile && $order->get_meta( '_epc_referral_processed', true ) ) {
            $result['message'] = 'already_processed';
            return $result;
        }

        $buyer_email = sanitize_email( (string) $order->get_billing_email() );
        $buyer_user  = (int) $order->get_user_id();
        $referred_member = $this->get_member_by_email_or_user( $buyer_email, $buyer_user );
        $referred_member_id = $referred_member ? (int) $referred_member->id : 0;
        $token    = (string) $order->get_meta( '_epc_referral_click_token', true );
        $ref_code = (string) $order->get_meta( '_epc_referral_code', true );
        if ( ! $force_reconcile && empty( $token ) && empty( $ref_code ) && $buyer_email === '' && $referred_member_id < 1 ) {
            $result['message'] = 'missing_ref_meta';
            return $result;
        }

        $click = $this->find_click_for_order( $order, $token, $ref_code, $buyer_email, $referred_member_id );
        if ( ! $click && ( $buyer_email !== '' || $referred_member_id > 0 ) ) {
            // Reconciliation fallback: locate an existing (possibly below-minimum) click by buyer identity.
            $click = $this->find_existing_click_for_buyer( $buyer_email, $referred_member_id );
        }
        if ( ! $click ) {
            $result['message'] = 'click_not_found';
            return $result;
        }

        // Self-referral guard.
        $referrer_id = (int) $click->referrer_member_id;
        if ( $referred_member && (int) $referred_member->id === $referrer_id ) {
            $order->update_meta_data( '_epc_referral_processed', '1' );
            if ( $force_reconcile ) {
                $order->update_meta_data( '_epc_referral_reconciled_at', current_time( 'mysql' ) );
                $order->update_meta_data( '_epc_referral_reconcile_result', 'self_referral_guard' );
            }
            $order->save();
            $result['message'] = 'self_referral_guard';
            return $result;
        }

        $min_order        = (float) EPC_Settings::get( 'epc_referral_min_order' );
        $is_below_minimum = ( $min_order > 0 && (float) $order->get_total() < $min_order );

        if ( ! $this->can_refer( $referrer_id ) ) {
            $order->update_meta_data( '_epc_referral_processed', '1' );
            if ( $force_reconcile ) {
                $order->update_meta_data( '_epc_referral_reconciled_at', current_time( 'mysql' ) );
                $order->update_meta_data( '_epc_referral_reconcile_result', 'max_referrals_reached' );
            }
            $order->save();
            $result['message'] = 'max_referrals_reached';
            return $result;
        }

        global $wpdb;

        $existing_purchase_order_id = (int) $click->purchased_order_id;
        if ( $existing_purchase_order_id > 0 && $existing_purchase_order_id !== $order_id ) {
            $existing_order = wc_get_order( $existing_purchase_order_id );
            $existing_total = $existing_order ? (float) $existing_order->get_total() : (float) $click->purchase_total;
            $existing_below_minimum = ( $min_order > 0 && $existing_total < $min_order );
            $can_upgrade_purchase   = ( $existing_below_minimum && ! $is_below_minimum );
            if ( ! $can_upgrade_purchase ) {
                $result['message'] = 'click_already_bound_to_other_order';
                return $result;
            }
        }

        $was_rewarded = ! empty( $click->rewarded_at );

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

        $updated = $wpdb->update(
            "{$wpdb->prefix}epc_referral_clicks",
            $update,
            [ 'id' => (int) $click->id ],
            $formats,
            [ '%d' ]
        );

        if ( false === $updated ) {
            $result['message'] = 'db_update_failed';
            $result['error']   = true;
            return $result;
        }

        $order->update_meta_data( '_epc_referral_processed', '1' );
        if ( $force_reconcile ) {
            $order->update_meta_data( '_epc_referral_reconciled_at', current_time( 'mysql' ) );
        }
        $order->save();

        do_action( 'epc_referral_purchase_recorded', $referrer_id, (int) $order_id );
        if ( ! $is_below_minimum ) {
            $this->grant_rewards_if_complete( (int) $click->id );
        }

        $fresh_click = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT rewarded_at FROM {$wpdb->prefix}epc_referral_clicks WHERE id = %d LIMIT 1",
                (int) $click->id
            )
        );

        $is_rewarded_now = ( $fresh_click && ! empty( $fresh_click->rewarded_at ) );
        $rewarded_now    = ( ! $was_rewarded && $is_rewarded_now );
        if ( $force_reconcile ) {
            $order->update_meta_data(
                '_epc_referral_reconcile_result',
                $is_below_minimum ? 'updated_purchase_below_min_order' : ( $rewarded_now ? 'updated_and_rewarded' : 'updated' )
            );
            $order->save();
        }

        $result['updated']      = true;
        $result['rewarded_now'] = $rewarded_now;
        $result['message']      = $is_below_minimum ? 'updated_purchase_below_min_order' : ( $rewarded_now ? 'updated_and_rewarded' : 'updated' );
        return $result;
    }

    /**
     * Locate an existing click row by buyer identity (for reconciliation fallbacks).
     */
    private function find_existing_click_for_buyer( string $buyer_email, int $buyer_member_id = 0 ) {
        global $wpdb;

        $buyer_email     = sanitize_email( $buyer_email );
        $buyer_member_id = (int) $buyer_member_id;
        if ( $buyer_email === '' && $buyer_member_id < 1 ) {
            return null;
        }

        $where = [ 'rewarded_at IS NULL' ];
        $args  = [];

        if ( $buyer_member_id > 0 && $buyer_email !== '' ) {
            $where[] = '(converted_member_id = %d OR LOWER(referred_email) = %s)';
            $args[]  = $buyer_member_id;
            $args[]  = strtolower( $buyer_email );
        } elseif ( $buyer_member_id > 0 ) {
            $where[] = 'converted_member_id = %d';
            $args[]  = $buyer_member_id;
        } else {
            $where[] = 'LOWER(referred_email) = %s';
            $args[]  = strtolower( $buyer_email );
        }

        $sql = "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                WHERE " . implode( ' AND ', $where ) . '
                ORDER BY first_clicked_at DESC LIMIT 1';

        return $wpdb->get_row( $wpdb->prepare( $sql, ...$args ) );
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
    private function find_click_for_order( \WC_Order $order, string $token, string $ref_code, string $buyer_email = '', int $buyer_member_id = 0 ) {
        global $wpdb;

        $buyer_email = sanitize_email( $buyer_email );
        $buyer_member_id = (int) $buyer_member_id;

        $is_self_referral_row = static function ( $row ) use ( $buyer_member_id ): bool {
            return ( $buyer_member_id > 0 && $row && (int) $row->referrer_member_id === $buyer_member_id );
        };

        $matches_buyer = static function ( $row ) use ( $buyer_email, $buyer_member_id ): bool {
            if ( ! $row ) {
                return false;
            }
            if ( $buyer_member_id > 0 && (int) $row->converted_member_id === $buyer_member_id ) {
                return true;
            }
            if ( $buyer_email !== '' && strtolower( trim( (string) $row->referred_email ) ) === strtolower( $buyer_email ) ) {
                return true;
            }

            return false;
        };

        if ( $token ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks WHERE cookie_token = %s LIMIT 1",
                    $token
                )
            );
            if ( $matches_buyer( $row ) && ! $is_self_referral_row( $row ) ) {
                return $row;
            }
        }

        if ( $ref_code ) {
            // First, try to match the current buyer to avoid stale-cookie mismatches.
            if ( $buyer_member_id > 0 || $buyer_email !== '' ) {
                $where = [ 'ref_code = %s', 'rewarded_at IS NULL' ];
                $args  = [ $ref_code ];
                if ( $buyer_member_id > 0 ) {
                    $where[] = 'referrer_member_id <> %d';
                    $args[]  = $buyer_member_id;
                }

                if ( $buyer_member_id > 0 ) {
                    $where[] = '(converted_member_id = %d OR converted_member_id IS NULL)';
                    $args[]  = $buyer_member_id;
                }
                if ( $buyer_email !== '' ) {
                    $where[] = "(LOWER(COALESCE(referred_email, '')) IN ('', %s) OR converted_member_id = %d)";
                    $args[]  = strtolower( $buyer_email );
                    $args[]  = max( 0, $buyer_member_id );
                }

                $sql = "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                        WHERE " . implode( ' AND ', $where ) . '
                        ORDER BY first_clicked_at DESC LIMIT 1';

                $row = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ) );
                if ( $row && ! $is_self_referral_row( $row ) ) {
                    return $row;
                }
            }

            $legacy_where = [ 'ref_code = %s', 'rewarded_at IS NULL' ];
            $legacy_args  = [ $ref_code ];
            if ( $buyer_member_id > 0 ) {
                $legacy_where[] = 'referrer_member_id <> %d';
                $legacy_args[]  = $buyer_member_id;
            }
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                      WHERE " . implode( ' AND ', $legacy_where ) . "
                      ORDER BY first_clicked_at DESC LIMIT 1",
                    ...$legacy_args
                )
            );
            if ( $row && ! $is_self_referral_row( $row ) ) {
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
                    'referred_email'     => $buyer_email,
                    'click_count'        => 0,
                    'first_clicked_at'   => current_time( 'mysql' ),
                    'last_clicked_at'    => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_referral_clicks WHERE cookie_token = %s LIMIT 1",
                    $synth_token
                )
            );
        }

        // Last-resort reconciliation: locate a pending click row for this buyer.
        if ( $buyer_member_id > 0 || $buyer_email !== '' ) {
            $where = [ 'rewarded_at IS NULL', 'purchased_order_id IS NULL' ];
            $args  = [];
            if ( $buyer_member_id > 0 ) {
                $where[] = 'referrer_member_id <> %d';
                $args[]  = $buyer_member_id;
            }
            if ( $buyer_member_id > 0 && $buyer_email !== '' ) {
                $where[] = '(converted_member_id = %d OR LOWER(referred_email) = %s)';
                $args[]  = $buyer_member_id;
                $args[]  = strtolower( $buyer_email );
            } elseif ( $buyer_member_id > 0 ) {
                $where[] = 'converted_member_id = %d';
                $args[]  = $buyer_member_id;
            } else {
                $where[] = 'LOWER(referred_email) = %s';
                $args[]  = strtolower( $buyer_email );
            }

            $sql = "SELECT * FROM {$wpdb->prefix}epc_referral_clicks
                    WHERE " . implode( ' AND ', $where ) . '
                    ORDER BY first_clicked_at DESC LIMIT 1';

            $row = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ) );
            if ( $row && ! $is_self_referral_row( $row ) ) {
                return $row;
            }
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
