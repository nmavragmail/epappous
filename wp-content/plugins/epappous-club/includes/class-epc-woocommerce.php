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

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_js' ] );
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

        if ( get_post_meta( $order_id, '_epc_points_earned', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
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
                $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'ids' ] );
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

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                $points,
                (int) $member['id']
            )
        );

        $wpdb->insert(
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

        update_post_meta( $order_id, '_epc_points_earned', $points );

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
        $discount  = (float) WC()->session->get( 'epc_points_discount', 0 );
        $pts_used  = (int) WC()->session->get( 'epc_points_used', 0 );
        $member_id = (int) WC()->session->get( 'epc_points_member_id', 0 );

        if ( $discount <= 0 || $pts_used < 1 || $member_id < 1 ) {
            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = GREATEST(0, CAST(points AS SIGNED) - %d) WHERE id = %d",
                $pts_used,
                $member_id
            )
        );

        $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => -$pts_used,
                'reason'         => 'checkout_redemption',
                'reference_type' => 'order',
                'reference_id'   => $order_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );

        update_post_meta( $order_id, '_epc_points_redeemed', $pts_used );
        update_post_meta( $order_id, '_epc_discount_amount', $discount );

        WC()->session->set( 'epc_points_discount', 0 );
        WC()->session->set( 'epc_points_used', 0 );
        WC()->session->set( 'epc_points_member_id', 0 );

        do_action( 'epc_points_changed', $member_id );
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
