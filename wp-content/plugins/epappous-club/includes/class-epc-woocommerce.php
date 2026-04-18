<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Integration
 *
 * - Earn points on order completion (respects tier multiplier, sale/category exclusions)
 * - Redeem points as cart discount at checkout
 */
class EPC_WooCommerce {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Earn points
        add_action( 'woocommerce_order_status_completed', [ $this, 'earn_points_on_order' ], 20 );

        // Redeem points at checkout
        add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'render_redeem_ui' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_redeem_ui' ] );
        add_action( 'wp_ajax_epc_apply_points_discount', [ $this, 'ajax_apply_discount' ] );
        add_action( 'wp_ajax_epc_remove_points_discount', [ $this, 'ajax_remove_discount' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_points_fee' ] );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'record_points_redemption' ], 20 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'record_points_redemption_from_blocks' ], 20, 2 );
        if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.2.0', '<' ) ) {
            add_action( '__experimental_woocommerce_blocks_checkout_update_order_from_request', [ $this, 'record_points_redemption_from_blocks' ], 20, 2 );
        }

        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_cassette_gift_order_panel' ], 15, 1 );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_js' ] );
    }

    /**
     * Show cassette gift status on order screen when the customer has user meta flag (Pappou Club).
     */
    public function render_cassette_gift_order_panel( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id < 1 ) {
            $email = $order->get_billing_email();
            if ( $email ) {
                $u = get_user_by( 'email', $email );
                if ( $u ) {
                    $user_id = (int) $u->ID;
                }
            }
        }

        if ( $user_id < 1 ) {
            return;
        }

        if ( get_user_meta( $user_id, 'epc_cassette_gift_received', true ) !== 'yes' ) {
            return;
        }

        $raw = get_user_meta( $user_id, 'epc_cassette_gift_date', true );
        $date_display = '';
        if ( $raw ) {
            $ts = strtotime( $raw . ' 12:00:00' );
            $date_display = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
        }

        $audit = EPC_User_Profile::get_cassette_audit_for_display( $user_id );

        ?>
        <div class="epc-order-cassette-gift card" style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;clear:both;">
            <strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'ePappous Club — Δώρο κασετίνα', 'epappous-club' ); ?></strong>
            <p style="margin:0;">
                <?php esc_html_e( 'Έχει πάρει δώρο κασετίνα:', 'epappous-club' ); ?>
                <strong><?php esc_html_e( 'Ναι', 'epappous-club' ); ?></strong>
                <?php if ( $date_display !== '' ) : ?>
                    — <?php esc_html_e( 'Ημερομηνία δώρου:', 'epappous-club' ); ?> <strong><?php echo esc_html( $date_display ); ?></strong>
                <?php else : ?>
                    — <?php esc_html_e( 'Ημερομηνία δώρου:', 'epappous-club' ); ?> <em><?php esc_html_e( 'δεν έχει οριστεί', 'epappous-club' ); ?></em>
                <?php endif; ?>
            </p>
            <?php if ( $audit ) : ?>
                <p style="margin:8px 0 0;font-size:12px;color:#50575e;">
                    <?php
                    printf(
                        /* translators: 1: admin name, 2: datetime */
                        esc_html__( 'Καταχώρηση πεδίου: %1$s — %2$s', 'epappous-club' ),
                        esc_html( $audit['editor_name'] ),
                        esc_html( $audit['edited_at'] )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Award points when an order is completed.
     */
    public function earn_points_on_order( $order_id ) {
        if ( EPC_Settings::get( 'epc_woo_earn_on_complete' ) !== '1' ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_epc_points_earned', true ) ) {
            return;
        }

        if ( ! EPC_B2BKing::order_customer_in_pappou_club( $order ) ) {
            return;
        }

        global $wpdb;
        $email  = $order->get_billing_email();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active'",
                $email
            ),
            ARRAY_A
        );

        if ( ! $member ) {
            return;
        }

        $points_per_euro    = (float) EPC_Settings::get( 'epc_points_per_euro' );
        $exclude_sale       = EPC_Settings::get( 'epc_woo_exclude_sale_items' ) === '1';
        $exclude_cats_json  = EPC_Settings::get( 'epc_woo_exclude_categories' );
        $exclude_cats       = json_decode( $exclude_cats_json, true ) ?: [];

        $tier_multiplier = $this->get_tier_multiplier( $member['tier'] );
        $eligible_total  = 0;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            if ( $exclude_sale && $product->is_on_sale() ) {
                continue;
            }

            if ( ! empty( $exclude_cats ) ) {
                $lookup_id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
                $product_cats = wp_get_post_terms( $lookup_id, 'product_cat', [ 'fields' => 'ids' ] );
                if ( array_intersect( $product_cats, $exclude_cats ) ) {
                    continue;
                }
            }

            $eligible_total += (float) $item->get_total();
        }

        if ( $eligible_total <= 0 ) {
            return;
        }

        $raw_points = $eligible_total * $points_per_euro;
        $points     = (int) floor( $raw_points * $tier_multiplier );

        if ( $points < 1 ) {
            return;
        }

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                $points,
                (int) $member['id']
            )
        );
        if ( 1 !== $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => (int) $member['id'],
                'points'         => $points,
                'reason'         => 'order_earning',
                'reference_type' => 'order',
                'reference_id'   => $order_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        try {
            $order->update_meta_data( '_epc_points_earned', $points );
            $order->save();
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_points_changed', (int) $member['id'] );
    }

    /**
     * Render the "Use points" UI in the cart/checkout totals.
     */
    public function render_redeem_ui() {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }

        global $wpdb;
        $user   = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            )
        );

        if ( ! $member ) {
            return;
        }

        if ( ! EPC_B2BKing::user_in_pappou_club( (int) $user->ID ) ) {
            return;
        }

        $min_points   = (int) EPC_Settings::get( 'epc_min_redeem_points' );
        $point_value  = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $max_percent  = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
        $member_pts   = (int) $member->points;
        $currency     = EPC_Settings::get( 'epc_currency_label' );

        if ( $member_pts < $min_points ) {
            return;
        }

        $cart_total    = (float) WC()->cart->get_subtotal();
        $max_discount  = $cart_total * ( $max_percent / 100 );
        $max_from_pts  = $member_pts * $point_value;
        $max_usable    = min( $max_discount, $max_from_pts );
        $points_needed = (int) floor( $max_usable / $point_value );

        $already_applied = WC()->session->get( 'epc_points_discount', 0 );
        ?>
        <tr class="epc-checkout-redeem">
            <th><?php echo esc_html( EPC_Settings::get( 'epc_club_name' ) ); ?></th>
            <td>
                <?php if ( $already_applied > 0 ) : ?>
                    <span class="epc-checkout-applied">
                        <?php printf(
                            esc_html__( 'Χρησιμοποιείτε %d %s (-%s)', 'epappous-club' ),
                            (int) WC()->session->get( 'epc_points_used', 0 ),
                            esc_html( $currency ),
                            wc_price( $already_applied )
                        ); ?>
                    </span>
                    <button type="button" class="epc-remove-points-btn" style="margin-left:8px;cursor:pointer;color:#ef4444;background:none;border:none;font-size:12px;">
                        <?php esc_html_e( 'Αφαίρεση', 'epappous-club' ); ?>
                    </button>
                <?php else : ?>
                    <span class="epc-checkout-info">
                        <?php printf(
                            esc_html__( 'Έχεις %d %s (αξία: %s, μέγιστο: %s)', 'epappous-club' ),
                            $member_pts,
                            esc_html( $currency ),
                            wc_price( $max_from_pts ),
                            wc_price( $max_usable )
                        ); ?>
                    </span>
                    <br />
                    <button type="button" class="epc-apply-points-btn button" data-max="<?php echo (int) $points_needed; ?>">
                        <?php printf( esc_html__( 'Χρήση %d %s', 'epappous-club' ), $points_needed, esc_html( $currency ) ); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * AJAX: apply points discount to session.
     */
    public function ajax_apply_discount() {
        check_ajax_referer( 'epc_front_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error();
        }

        global $wpdb;
        $user   = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            )
        );

        if ( ! $member ) {
            wp_send_json_error();
        }

        if ( ! EPC_B2BKing::user_in_pappou_club( (int) $user->ID ) ) {
            wp_send_json_error();
        }

        $point_value  = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $max_percent  = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
        $cart_total   = (float) WC()->cart->get_subtotal();
        $max_discount = $cart_total * ( $max_percent / 100 );
        $max_from_pts = (int) $member->points * $point_value;
        $discount     = min( $max_discount, $max_from_pts );
        $pts_used     = (int) floor( $discount / $point_value );

        WC()->session->set( 'epc_points_discount', $discount );
        WC()->session->set( 'epc_points_used', $pts_used );
        WC()->session->set( 'epc_points_member_id', (int) $member->id );

        wp_send_json_success();
    }

    /**
     * AJAX: remove points discount.
     */
    public function ajax_remove_discount() {
        check_ajax_referer( 'epc_front_nonce', 'nonce' );

        WC()->session->set( 'epc_points_discount', 0 );
        WC()->session->set( 'epc_points_used', 0 );
        WC()->session->set( 'epc_points_member_id', 0 );

        wp_send_json_success();
    }

    /**
     * Apply session-stored discount as a negative fee.
     */
    public function apply_points_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $discount = (float) WC()->session->get( 'epc_points_discount', 0 );
        if ( $discount > 0 ) {
            if ( is_user_logged_in() && ! EPC_B2BKing::user_in_pappou_club( (int) get_current_user_id() ) ) {
                $this->clear_points_discount_session();
                return;
            }

            $max_percent   = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
            $cart_subtotal = (float) $cart->get_subtotal();
            $max_discount  = $cart_subtotal * ( $max_percent / 100 );
            $discount      = min( $discount, $max_discount );

            if ( $discount <= 0 ) {
                $this->clear_points_discount_session();
                return;
            }

            $label = sprintf(
                __( 'Έκπτωση %s', 'epappous-club' ),
                EPC_Settings::get( 'epc_club_name' )
            );
            $cart->add_fee( $label, -$discount );
        }
    }

    /**
     * Deduct points from member when order is placed with points discount.
     */
    public function record_points_redemption( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_epc_points_redeemed', true ) ) {
            $this->clear_points_discount_session();
            return;
        }

        $discount  = (float) WC()->session->get( 'epc_points_discount', 0 );
        $pts_used  = (int) WC()->session->get( 'epc_points_used', 0 );
        $member_id = (int) WC()->session->get( 'epc_points_member_id', 0 );

        if ( $discount <= 0 || $pts_used < 1 || $member_id < 1 ) {
            return;
        }

        $this->commit_points_redemption( $order, $member_id, $pts_used, $discount );
        $this->clear_points_discount_session();
    }

    /**
     * Persist block-checkout points redemption data on order creation.
     */
    public function record_points_redemption_from_blocks( $order, $request = null ) {
        if ( ! $order ) {
            $this->clear_points_discount_session();
            return;
        }

        if ( $order->get_meta( '_epc_points_redeemed', true ) ) {
            $this->clear_points_discount_session();
            return;
        }

        $extensions = [];
        if ( $request instanceof \WP_REST_Request ) {
            $ext = $request->get_param( 'extensions' );
            $extensions = is_array( $ext ) ? $ext : [];
        } elseif ( is_array( $request ) && isset( $request['extensions'] ) && is_array( $request['extensions'] ) ) {
            $extensions = $request['extensions'];
        }
        $payload = is_array( $extensions['epappous-club'] ?? null ) ? $extensions['epappous-club'] : [];

        $member_id = isset( $payload['member_id'] ) ? (int) $payload['member_id'] : (int) WC()->session->get( 'epc_points_member_id', 0 );
        $pts_used  = isset( $payload['points_used'] ) ? (int) $payload['points_used'] : (int) WC()->session->get( 'epc_points_used', 0 );
        $discount  = isset( $payload['discount'] ) ? (float) $payload['discount'] : (float) WC()->session->get( 'epc_points_discount', 0 );

        if ( $discount <= 0 || $pts_used < 1 || $member_id < 1 ) {
            return;
        }

        $this->commit_points_redemption( $order, $member_id, $pts_used, $discount );
        $this->clear_points_discount_session();
    }

    /**
     * Apply idempotent points deduction and log entry.
     */
    private function commit_points_redemption( $order, $member_id, $pts_used, $discount ) {
        if ( ! EPC_B2BKing::order_customer_in_pappou_club( $order ) ) {
            $this->clear_points_discount_session();
            $order->add_order_note(
                __( 'ePappous Club: Εξαργύρωση πόντων δεν εφαρμόστηκε — ο λογαριασμός δεν ανήκει στην ομάδα Pappou Club (B2B King).', 'epappous-club' )
            );
            $order->save();
            return;
        }

        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = CAST(points AS SIGNED) - %d WHERE id = %d AND CAST(points AS SIGNED) >= %d",
                $pts_used,
                $member_id,
                $pts_used
            )
        );
        if ( $updated !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note(
                __( 'ePappous Club: Η αφαίρεση πόντων απέτυχε, πιθανώς λόγω ανεπαρκούς υπολοίπου κατά την ολοκλήρωση.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => -$pts_used,
                'reason'         => 'checkout_redemption',
                'reference_type' => 'order',
                'reference_id'   => $order->get_id(),
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note(
                __( 'ePappous Club: Αποτυχία καταγραφής εξαργύρωσης πόντων.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        try {
            $order->update_meta_data( '_epc_points_redeemed', $pts_used );
            $order->update_meta_data( '_epc_discount_amount', $discount );
            $order->save();
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note(
                __( 'ePappous Club: Αποτυχία αποθήκευσης μετα-δεδομένων παραγγελίας.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_points_changed', (int) $member_id );
    }

    /**
     * Reset session values used for points redemptions.
     */
    private function clear_points_discount_session() {
        if ( ! WC()->session ) {
            return;
        }
        WC()->session->set( 'epc_points_discount', 0 );
        WC()->session->set( 'epc_points_used', 0 );
        WC()->session->set( 'epc_points_member_id', 0 );
    }

    /**
     * Enqueue checkout JS for points buttons.
     */
    public function enqueue_checkout_js() {
        if ( ! function_exists( 'is_cart' ) ) {
            return;
        }
        if ( ! is_cart() && ! is_checkout() ) {
            return;
        }

        wp_enqueue_script( 'epc-checkout-js', EPC_PLUGIN_URL . 'admin/js/checkout.js', [ 'jquery' ], EPC_VERSION, true );
        wp_localize_script( 'epc-checkout-js', 'epcCheckout', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'epc_front_nonce' ),
        ] );
    }

    private function get_tier_multiplier( string $tier ): float {
        $tiers_json = EPC_Settings::get( 'epc_tiers' );
        $tiers = json_decode( $tiers_json, true );
        if ( ! is_array( $tiers ) ) {
            return 1.0;
        }
        foreach ( $tiers as $t ) {
            if ( ( $t['slug'] ?? '' ) === $tier ) {
                return (float) ( $t['multiplier'] ?? 1.0 );
            }
        }
        return 1.0;
    }
}
