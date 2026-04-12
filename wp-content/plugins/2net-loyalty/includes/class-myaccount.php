<?php
defined( 'ABSPATH' ) || exit;

/**
 * My Account — adds "Πόντοι" tab with balance, history,
 * referral link, gift redemption, and coupon purchase.
 */
class TwoNet_MyAccount {

    private static $instance = null;

    const ENDPOINT = 'loyalty-points';

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

        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'query_vars', [ $this, 'add_query_var' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_endpoint' ] );

        // Flush rewrite rules once.
        add_action( 'wp_loaded', [ $this, 'maybe_flush_rules' ] );
    }

    public function add_endpoint() {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public function add_query_var( $vars ) {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public function add_menu_item( $items ) {
        $new_items = [];

        foreach ( $items as $key => $label ) {
            $new_items[ $key ] = $label;

            // Insert after dashboard.
            if ( 'dashboard' === $key ) {
                $new_items[ self::ENDPOINT ] = __( 'Πόντοι', '2net-loyalty' );
            }
        }

        return $new_items;
    }

    public function maybe_flush_rules() {
        if ( get_option( '2net_loyalty_flushed_rules' ) !== TWONET_LOYALTY_VERSION ) {
            flush_rewrite_rules();
            update_option( '2net_loyalty_flushed_rules', TWONET_LOYALTY_VERSION );
        }
    }

    /* ------------------------------------------------------------------
     * Render the tab content
     * ----------------------------------------------------------------*/

    public function render_endpoint() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $balance        = TwoNet_Points_Manager::get_balance( $user_id );
        $referral_code  = get_user_meta( $user_id, TwoNet_Bonus_Handler::REFERRAL_META, true );
        $referral_url   = $referral_code ? add_query_arg( 'ref', $referral_code, wc_get_page_permalink( 'myaccount' ) ) : '';

        $redeem_amount  = (int) TwoNet_Loyalty_Core::get_setting( 'redeem_points_amount', 250 );
        $discount_value = (float) TwoNet_Loyalty_Core::get_setting( 'redeem_discount_value', 2 );
        $coupon_cost    = (int) TwoNet_Loyalty_Core::get_setting( 'coupon_points_cost', 125 );
        $coupon_value   = (float) TwoNet_Loyalty_Core::get_setting( 'coupon_value', 2 );

        // Pagination.
        $per_page  = 15;
        $paged     = isset( $_GET['points_page'] ) ? max( 1, absint( $_GET['points_page'] ) ) : 1;
        $offset    = ( $paged - 1 ) * $per_page;
        $total     = TwoNet_Points_Manager::count_history( $user_id );
        $history   = TwoNet_Points_Manager::get_history( $user_id, $per_page, $offset );

        // Available gifts.
        $gift_cat = (int) TwoNet_Loyalty_Core::get_setting( 'gift_category_id', 784 );
        $gifts    = wc_get_products( [
            'category' => [ get_term( $gift_cat, 'product_cat' ) ? get_term( $gift_cat, 'product_cat' )->slug : '' ],
            'limit'    => 50,
            'status'   => 'publish',
        ] );

        // Load template.
        $template = TWONET_LOYALTY_PATH . 'templates/myaccount-points.php';
        if ( file_exists( $template ) ) {
            include $template;
        }
    }
}
