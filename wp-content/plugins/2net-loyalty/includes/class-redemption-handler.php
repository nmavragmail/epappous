<?php
defined( 'ABSPATH' ) || exit;

/**
 * Redemption Handler — redeem points for:
 *   1. Cart discount (250 pts = €2)
 *   2. Free gifts from gift category
 *   3. Store coupons (125 pts = €2 coupon)
 */
class TwoNet_Redemption_Handler {

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

        // AJAX endpoints for logged-in users.
        add_action( 'wp_ajax_2net_redeem_discount', [ $this, 'ajax_redeem_discount' ] );
        add_action( 'wp_ajax_2net_redeem_gift',     [ $this, 'ajax_redeem_gift' ] );
        add_action( 'wp_ajax_2net_buy_coupon',      [ $this, 'ajax_buy_coupon' ] );

        // Display redeem UI on cart page.
        add_action( 'woocommerce_before_cart', [ $this, 'render_cart_redeem_ui' ] );

        // Display available gifts on My Account points page (handled in MyAccount class).
    }

    /* ------------------------------------------------------------------
     * 1. Redeem points → cart discount
     * ----------------------------------------------------------------*/

    public function ajax_redeem_discount() {
        check_ajax_referer( '2net_loyalty_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Πρέπει να συνδεθείτε.', '2net-loyalty' ) ] );
        }

        $user_id        = get_current_user_id();
        $multiples      = isset( $_POST['multiples'] ) ? absint( $_POST['multiples'] ) : 1;
        $redeem_amount  = (int) TwoNet_Loyalty_Core::get_setting( 'redeem_points_amount', 250 );
        $discount_value = (float) TwoNet_Loyalty_Core::get_setting( 'redeem_discount_value', 2 );

        $points_needed = $redeem_amount * $multiples;
        $discount      = $discount_value * $multiples;

        $balance = TwoNet_Points_Manager::get_balance( $user_id );
        if ( $balance < $points_needed ) {
            wp_send_json_error( [ 'message' => __( 'Δεν έχετε αρκετούς πόντους.', '2net-loyalty' ) ] );
        }

        // Create a one-time coupon for this discount.
        $coupon_code = '2net-redeem-' . $user_id . '-' . time();
        $coupon_id   = $this->create_wc_coupon( $coupon_code, $discount, [
            'discount_type'       => 'fixed_cart',
            'usage_limit'         => 1,
            'individual_use'      => false,
            'email_restrictions'  => [],
        ] );

        if ( ! $coupon_id ) {
            wp_send_json_error( [ 'message' => __( 'Σφάλμα δημιουργίας κουπονιού.', '2net-loyalty' ) ] );
        }

        $result = TwoNet_Points_Manager::deduct_points(
            $user_id,
            $points_needed,
            'redeem',
            $coupon_id,
            sprintf( __( 'Εξαργύρωση %d πόντων → έκπτωση %s€', '2net-loyalty' ), $points_needed, number_format( $discount, 2 ) )
        );

        if ( false === $result ) {
            wp_delete_post( $coupon_id, true );
            wp_send_json_error( [ 'message' => __( 'Αποτυχία αφαίρεσης πόντων.', '2net-loyalty' ) ] );
        }

        WC()->cart->apply_coupon( $coupon_code );

        wp_send_json_success( [
            'message'     => sprintf( __( 'Εφαρμόστηκε έκπτωση %s€!', '2net-loyalty' ), number_format( $discount, 2 ) ),
            'new_balance' => $result,
        ] );
    }

    /* ------------------------------------------------------------------
     * 2. Redeem points → free gift
     * ----------------------------------------------------------------*/

    public function ajax_redeem_gift() {
        check_ajax_referer( '2net_loyalty_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Πρέπει να συνδεθείτε.', '2net-loyalty' ) ] );
        }

        $user_id    = get_current_user_id();
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => __( 'Δεν επιλέχτηκε προϊόν.', '2net-loyalty' ) ] );
        }

        // Validate product is in the gift category.
        $gift_cat = (int) TwoNet_Loyalty_Core::get_setting( 'gift_category_id', 784 );
        if ( ! has_term( $gift_cat, 'product_cat', $product_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Αυτό το προϊόν δεν είναι διαθέσιμο ως δώρο.', '2net-loyalty' ) ] );
        }

        // Points cost is from _loyalty_points meta (gifts must have this set).
        $points_cost = absint( get_post_meta( $product_id, '_loyalty_points', true ) );
        if ( $points_cost <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Αυτό το δώρο δεν έχει ορισμένο κόστος πόντων.', '2net-loyalty' ) ] );
        }

        $balance = TwoNet_Points_Manager::get_balance( $user_id );
        if ( $balance < $points_cost ) {
            wp_send_json_error( [ 'message' => __( 'Δεν έχετε αρκετούς πόντους.', '2net-loyalty' ) ] );
        }

        $product = wc_get_product( $product_id );
        $result  = TwoNet_Points_Manager::deduct_points(
            $user_id,
            $points_cost,
            'gift',
            $product_id,
            sprintf( __( 'Δώρο: %s (%d πόντοι)', '2net-loyalty' ), $product->get_name(), $points_cost )
        );

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => __( 'Αποτυχία αφαίρεσης πόντων.', '2net-loyalty' ) ] );
        }

        // Add gift product to cart with zero price.
        $cart_item_key = WC()->cart->add_to_cart( $product_id, 1, 0, [], [
            '_2net_loyalty_gift' => true,
            '_2net_points_cost'  => $points_cost,
        ] );

        if ( ! $cart_item_key ) {
            // Refund points if cart add fails.
            TwoNet_Points_Manager::add_points(
                $user_id,
                $points_cost,
                'refund',
                $product_id,
                __( 'Επιστροφή πόντων — αποτυχία προσθήκης δώρου', '2net-loyalty' )
            );
            wp_send_json_error( [ 'message' => __( 'Δεν ήταν δυνατή η προσθήκη στο καλάθι.', '2net-loyalty' ) ] );
        }

        wp_send_json_success( [
            'message'     => sprintf( __( 'Το δώρο "%s" προστέθηκε στο καλάθι!', '2net-loyalty' ), $product->get_name() ),
            'new_balance' => $result,
        ] );
    }

    /* ------------------------------------------------------------------
     * 3. Buy coupon with points
     * ----------------------------------------------------------------*/

    public function ajax_buy_coupon() {
        check_ajax_referer( '2net_loyalty_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Πρέπει να συνδεθείτε.', '2net-loyalty' ) ] );
        }

        $user_id      = get_current_user_id();
        $points_cost  = (int) TwoNet_Loyalty_Core::get_setting( 'coupon_points_cost', 125 );
        $coupon_value = (float) TwoNet_Loyalty_Core::get_setting( 'coupon_value', 2 );
        $multiples    = isset( $_POST['multiples'] ) ? absint( $_POST['multiples'] ) : 1;

        $total_cost  = $points_cost * $multiples;
        $total_value = $coupon_value * $multiples;

        $balance = TwoNet_Points_Manager::get_balance( $user_id );
        if ( $balance < $total_cost ) {
            wp_send_json_error( [ 'message' => __( 'Δεν έχετε αρκετούς πόντους.', '2net-loyalty' ) ] );
        }

        $coupon_code = '2net-coupon-' . $user_id . '-' . time();
        $coupon_id   = $this->create_wc_coupon( $coupon_code, $total_value, [
            'discount_type'  => 'fixed_cart',
            'usage_limit'    => 1,
            'individual_use' => false,
        ] );

        if ( ! $coupon_id ) {
            wp_send_json_error( [ 'message' => __( 'Σφάλμα δημιουργίας κουπονιού.', '2net-loyalty' ) ] );
        }

        $result = TwoNet_Points_Manager::deduct_points(
            $user_id,
            $total_cost,
            'coupon',
            $coupon_id,
            sprintf( __( 'Αγορά κουπονιού %s€ (%d πόντοι)', '2net-loyalty' ), number_format( $total_value, 2 ), $total_cost )
        );

        if ( false === $result ) {
            wp_delete_post( $coupon_id, true );
            wp_send_json_error( [ 'message' => __( 'Αποτυχία αφαίρεσης πόντων.', '2net-loyalty' ) ] );
        }

        // Email the coupon code to the user.
        $user = get_userdata( $user_id );
        wp_mail(
            $user->user_email,
            __( 'Το κουπόνι σας — 2NET Loyalty', '2net-loyalty' ),
            sprintf(
                __( "Γεια σας %s,\n\nΟ κωδικός κουπονιού σας αξίας %s€ είναι: %s\n\nΧρησιμοποιήστε τον στο checkout!\n\nΕυχαριστούμε,\n2NET", '2net-loyalty' ),
                $user->display_name,
                number_format( $total_value, 2 ),
                $coupon_code
            )
        );

        wp_send_json_success( [
            'message'     => sprintf( __( 'Κουπόνι %s€ δημιουργήθηκε! Κωδικός: %s', '2net-loyalty' ), number_format( $total_value, 2 ), $coupon_code ),
            'coupon_code' => $coupon_code,
            'new_balance' => $result,
        ] );
    }

    /* ------------------------------------------------------------------
     * Gift product price override — set to zero in cart
     * ----------------------------------------------------------------*/

    /**
     * Zero out gift item prices.
     * Hook is registered statically so it runs even before instance creation.
     */
    public static function register_gift_price_hooks() {
        add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'set_gift_price_zero' ], 99 );
    }

    public static function set_gift_price_zero( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['_2net_loyalty_gift'] ) ) {
                $item['data']->set_price( 0 );
            }
        }
    }

    /* ------------------------------------------------------------------
     * Cart redeem UI
     * ----------------------------------------------------------------*/

    public function render_cart_redeem_ui() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id        = get_current_user_id();
        $balance        = TwoNet_Points_Manager::get_balance( $user_id );
        $redeem_amount  = (int) TwoNet_Loyalty_Core::get_setting( 'redeem_points_amount', 250 );
        $discount_value = (float) TwoNet_Loyalty_Core::get_setting( 'redeem_discount_value', 2 );
        $min_redeem     = (int) TwoNet_Loyalty_Core::get_setting( 'min_redeem_points', 250 );

        if ( $balance < $min_redeem ) {
            return;
        }

        $max_multiples = floor( $balance / $redeem_amount );
        ?>
        <div class="twonet-loyalty-cart-redeem">
            <h3><?php esc_html_e( '2NET Loyalty — Εξαργύρωση πόντων', '2net-loyalty' ); ?></h3>
            <p>
                <?php printf(
                    esc_html__( 'Έχετε %d πόντους. Κάθε %d πόντοι = %s€ έκπτωση.', '2net-loyalty' ),
                    $balance,
                    $redeem_amount,
                    number_format( $discount_value, 2 )
                ); ?>
            </p>
            <form class="twonet-redeem-form" data-action="2net_redeem_discount">
                <label for="twonet-redeem-multiples">
                    <?php esc_html_e( 'Πόσα σετ θέλετε να εξαργυρώσετε;', '2net-loyalty' ); ?>
                </label>
                <input type="number" id="twonet-redeem-multiples" name="multiples" value="1" min="1" max="<?php echo esc_attr( $max_multiples ); ?>">
                <button type="submit" class="button"><?php esc_html_e( 'Εξαργύρωση', '2net-loyalty' ); ?></button>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Helper: create a WC coupon programmatically
     * ----------------------------------------------------------------*/

    private function create_wc_coupon( string $code, float $amount, array $args = [] ): int {
        $defaults = [
            'discount_type'       => 'fixed_cart',
            'usage_limit'         => 1,
            'individual_use'      => false,
            'free_shipping'       => false,
            'email_restrictions'  => [],
        ];
        $args = wp_parse_args( $args, $defaults );

        $coupon = new \WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_amount( $amount );
        $coupon->set_discount_type( $args['discount_type'] );
        $coupon->set_usage_limit( $args['usage_limit'] );
        $coupon->set_individual_use( $args['individual_use'] );
        $coupon->set_free_shipping( $args['free_shipping'] );

        if ( ! empty( $args['email_restrictions'] ) ) {
            $coupon->set_email_restrictions( $args['email_restrictions'] );
        }

        $coupon_id = $coupon->save();
        return $coupon_id ?: 0;
    }
}

// Register gift price hook early.
TwoNet_Redemption_Handler::register_gift_price_hooks();
