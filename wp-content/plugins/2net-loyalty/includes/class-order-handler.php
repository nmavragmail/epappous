<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order Handler — award points when orders are completed,
 * handle refunds, display points info on products.
 */
class TwoNet_Order_Handler {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! TwoNet_Loyalty_Core::is_enabled() ) {
            return;
        }

        // Award points on order completion.
        add_action( 'woocommerce_order_status_completed', [ $this, 'award_order_points' ], 10, 1 );

        // Revoke points on full refund or cancellation.
        add_action( 'woocommerce_order_status_refunded',  [ $this, 'revoke_order_points' ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled',  [ $this, 'revoke_order_points' ], 10, 1 );

        // Display points badge on product pages and loops.
        add_action( 'woocommerce_single_product_summary', [ $this, 'show_product_points' ], 25 );
        add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'show_product_points_loop' ], 15 );

        // Product meta box in admin for setting points.
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_points_meta_field' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_points_meta_field' ] );

        // Show earned points in cart / checkout.
        add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'show_cart_points_preview' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'show_cart_points_preview' ] );
    }

    /* ------------------------------------------------------------------
     * Award points on order completed
     * ----------------------------------------------------------------*/

    public function award_order_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) {
            return;
        }

        // Idempotency: don't award twice.
        if ( TwoNet_Points_Manager::has_transaction( $user_id, 'order', $order_id ) ) {
            return;
        }

        $total_points = $this->calculate_order_points( $order );

        if ( $total_points > 0 ) {
            TwoNet_Points_Manager::add_points(
                $user_id,
                $total_points,
                'order',
                $order_id,
                sprintf( __( 'Πόντοι από παραγγελία #%d', '2net-loyalty' ), $order_id )
            );

            $order->add_order_note(
                sprintf( __( '2NET Loyalty: +%d πόντοι στον πελάτη.', '2net-loyalty' ), $total_points )
            );
        }
    }

    /**
     * Calculate total points for an order based on each product's _loyalty_points meta.
     */
    public function calculate_order_points( $order ): int {
        $total = 0;

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $quantity   = $item->get_quantity();

            $product_points = $this->get_product_points( $product_id );

            if ( $product_points > 0 ) {
                $total += $product_points * $quantity;
            }
        }

        return $total;
    }

    /**
     * Get points assigned to a product.
     * Checks _loyalty_points meta; falls back to points_per_euro * price.
     */
    public function get_product_points( int $product_id ): int {
        $points = get_post_meta( $product_id, '_loyalty_points', true );

        if ( '' !== $points && false !== $points ) {
            return absint( $points );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return 0;
        }

        $price          = (float) $product->get_price();
        $points_per_euro = (int) TwoNet_Loyalty_Core::get_setting( 'points_per_euro', 1 );

        return (int) floor( $price * $points_per_euro );
    }

    /* ------------------------------------------------------------------
     * Revoke points on refund / cancellation
     * ----------------------------------------------------------------*/

    public function revoke_order_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) {
            return;
        }

        // Only revoke if points were previously awarded.
        if ( ! TwoNet_Points_Manager::has_transaction( $user_id, 'order', $order_id ) ) {
            return;
        }

        // Idempotency: don't revoke twice.
        if ( TwoNet_Points_Manager::has_transaction( $user_id, 'refund', $order_id ) ) {
            return;
        }

        $total_points = $this->calculate_order_points( $order );

        if ( $total_points > 0 ) {
            TwoNet_Points_Manager::deduct_points(
                $user_id,
                $total_points,
                'refund',
                $order_id,
                sprintf( __( 'Αφαίρεση πόντων — ακύρωση/επιστροφή #%d', '2net-loyalty' ), $order_id )
            );

            $order->add_order_note(
                sprintf( __( '2NET Loyalty: -%d πόντοι (ακύρωση/επιστροφή).', '2net-loyalty' ), $total_points )
            );
        }
    }

    /* ------------------------------------------------------------------
     * Product display
     * ----------------------------------------------------------------*/

    public function show_product_points() {
        global $product;
        if ( ! $product ) {
            return;
        }

        $points = $this->get_product_points( $product->get_id() );
        if ( $points > 0 ) {
            printf(
                '<p class="twonet-loyalty-product-points">%s <strong>%d</strong> %s</p>',
                esc_html__( 'Κερδίζεις', '2net-loyalty' ),
                $points,
                esc_html__( 'πόντους με αυτό το προϊόν', '2net-loyalty' )
            );
        }
    }

    public function show_product_points_loop() {
        global $product;
        if ( ! $product ) {
            return;
        }

        $points = $this->get_product_points( $product->get_id() );
        if ( $points > 0 ) {
            printf(
                '<span class="twonet-loyalty-points-badge">+%d %s</span>',
                $points,
                esc_html__( 'πόντοι', '2net-loyalty' )
            );
        }
    }

    /* ------------------------------------------------------------------
     * Admin: product points meta field
     * ----------------------------------------------------------------*/

    public function add_points_meta_field() {
        woocommerce_wp_text_input( [
            'id'          => '_loyalty_points',
            'label'       => __( 'Πόντοι Loyalty', '2net-loyalty' ),
            'desc_tip'    => true,
            'description' => __( 'Πόντοι που κερδίζει ο πελάτης αγοράζοντας αυτό το προϊόν. Αφήστε κενό για αυτόματο υπολογισμό.', '2net-loyalty' ),
            'type'        => 'number',
            'custom_attributes' => [
                'min'  => '0',
                'step' => '1',
            ],
        ] );
    }

    public function save_points_meta_field( $post_id ) {
        if ( isset( $_POST['_loyalty_points'] ) ) {
            $points = sanitize_text_field( wp_unslash( $_POST['_loyalty_points'] ) );
            update_post_meta( $post_id, '_loyalty_points', '' !== $points ? absint( $points ) : '' );
        }
    }

    /* ------------------------------------------------------------------
     * Cart preview: "You will earn X points"
     * ----------------------------------------------------------------*/

    public function show_cart_points_preview() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $total_points = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            $product_id = $item['product_id'];
            $quantity   = $item['quantity'];
            $total_points += $this->get_product_points( $product_id ) * $quantity;
        }

        if ( $total_points > 0 ) {
            printf(
                '<tr class="twonet-loyalty-cart-points"><th>%s</th><td><strong>+%d</strong> %s</td></tr>',
                esc_html__( 'Πόντοι Loyalty', '2net-loyalty' ),
                $total_points,
                esc_html__( 'πόντοι', '2net-loyalty' )
            );
        }
    }
}
