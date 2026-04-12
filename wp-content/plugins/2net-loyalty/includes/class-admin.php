<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin — settings page under WooCommerce menu,
 * user profile points display/manual adjustment.
 */
class TwoNet_Loyalty_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // User profile.
        add_action( 'show_user_profile', [ $this, 'show_user_points' ] );
        add_action( 'edit_user_profile', [ $this, 'show_user_points' ] );
        add_action( 'personal_options_update',  [ $this, 'save_user_points_adjustment' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_user_points_adjustment' ] );

        // Admin styles.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX: manual points adjustment from user list.
        add_action( 'wp_ajax_2net_admin_adjust_points', [ $this, 'ajax_adjust_points' ] );

        // Users list column.
        add_filter( 'manage_users_columns', [ $this, 'add_users_column' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_users_column' ], 10, 3 );
        add_filter( 'manage_users_sortable_columns', [ $this, 'sortable_users_column' ] );
        add_action( 'pre_get_users', [ $this, 'sort_by_points' ] );
    }

    /* ------------------------------------------------------------------
     * Menu & settings
     * ----------------------------------------------------------------*/

    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __( '2NET Loyalty — Ρυθμίσεις', '2net-loyalty' ),
            __( '2NET Loyalty', '2net-loyalty' ),
            'manage_woocommerce',
            '2net-loyalty-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( '2net_loyalty_group', TwoNet_Loyalty_Core::SETTINGS_KEY, [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ) {
        $clean = [];

        $clean['enabled']                = isset( $input['enabled'] ) ? 'yes' : 'no';
        $clean['points_per_euro']        = max( 0, absint( $input['points_per_euro'] ?? 1 ) );
        $clean['redeem_points_amount']   = max( 1, absint( $input['redeem_points_amount'] ?? 250 ) );
        $clean['redeem_discount_value']  = max( 0.01, floatval( $input['redeem_discount_value'] ?? 2 ) );
        $clean['coupon_points_cost']     = max( 1, absint( $input['coupon_points_cost'] ?? 125 ) );
        $clean['coupon_value']           = max( 0.01, floatval( $input['coupon_value'] ?? 2 ) );
        $clean['gift_category_id']       = absint( $input['gift_category_id'] ?? 784 );
        $clean['welcome_coupon_percent'] = min( 100, max( 1, absint( $input['welcome_coupon_percent'] ?? 20 ) ) );
        $clean['registration_bonus']     = max( 0, absint( $input['registration_bonus'] ?? 500 ) );
        $clean['referral_bonus']         = max( 0, absint( $input['referral_bonus'] ?? 400 ) );
        $clean['birthday_bonus']         = max( 0, absint( $input['birthday_bonus'] ?? 1500 ) );
        $clean['min_redeem_points']      = max( 1, absint( $input['min_redeem_points'] ?? 250 ) );

        return $clean;
    }

    public function render_settings_page() {
        $s = get_option( TwoNet_Loyalty_Core::SETTINGS_KEY, TwoNet_Loyalty_Core::defaults() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( '2NET Loyalty — Ρυθμίσεις', '2net-loyalty' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( '2net_loyalty_group' ); ?>

                <table class="form-table">
                    <!-- Enabled -->
                    <tr>
                        <th><?php esc_html_e( 'Ενεργοποίηση', '2net-loyalty' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[enabled]"
                                       value="yes" <?php checked( $s['enabled'] ?? 'yes', 'yes' ); ?>>
                                <?php esc_html_e( 'Ενεργό', '2net-loyalty' ); ?>
                            </label>
                        </td>
                    </tr>

                    <!-- Points per Euro -->
                    <tr>
                        <th><?php esc_html_e( 'Πόντοι ανά €1 (αυτόματος υπολογισμός)', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[points_per_euro]"
                                   value="<?php echo esc_attr( $s['points_per_euro'] ?? 1 ); ?>" min="0" step="1" class="small-text">
                            <p class="description"><?php esc_html_e( 'Χρησιμοποιείται μόνο αν το προϊόν δεν έχει ρητά ορισμένους πόντους.', '2net-loyalty' ); ?></p>
                        </td>
                    </tr>

                    <!-- Redeem discount -->
                    <tr>
                        <th><?php esc_html_e( 'Εξαργύρωση — πόντοι ανά σετ', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[redeem_points_amount]"
                                   value="<?php echo esc_attr( $s['redeem_points_amount'] ?? 250 ); ?>" min="1" class="small-text">
                            <?php esc_html_e( 'πόντοι =', '2net-loyalty' ); ?>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[redeem_discount_value]"
                                   value="<?php echo esc_attr( $s['redeem_discount_value'] ?? 2 ); ?>" min="0.01" step="0.01" class="small-text">
                            €
                        </td>
                    </tr>

                    <!-- Coupon purchase -->
                    <tr>
                        <th><?php esc_html_e( 'Αγορά κουπονιού', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[coupon_points_cost]"
                                   value="<?php echo esc_attr( $s['coupon_points_cost'] ?? 125 ); ?>" min="1" class="small-text">
                            <?php esc_html_e( 'πόντοι =', '2net-loyalty' ); ?>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[coupon_value]"
                                   value="<?php echo esc_attr( $s['coupon_value'] ?? 2 ); ?>" min="0.01" step="0.01" class="small-text">
                            €
                        </td>
                    </tr>

                    <!-- Gift category -->
                    <tr>
                        <th><?php esc_html_e( 'Κατηγορία δώρων (ID)', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[gift_category_id]"
                                   value="<?php echo esc_attr( $s['gift_category_id'] ?? 784 ); ?>" min="0" class="small-text">
                        </td>
                    </tr>

                    <!-- Welcome coupon -->
                    <tr>
                        <th><?php esc_html_e( 'Welcome κουπόνι (%)', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[welcome_coupon_percent]"
                                   value="<?php echo esc_attr( $s['welcome_coupon_percent'] ?? 20 ); ?>" min="1" max="100" class="small-text">%
                        </td>
                    </tr>

                    <!-- Registration bonus -->
                    <tr>
                        <th><?php esc_html_e( 'Δώρο εγγραφής (πόντοι)', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[registration_bonus]"
                                   value="<?php echo esc_attr( $s['registration_bonus'] ?? 500 ); ?>" min="0" class="small-text">
                        </td>
                    </tr>

                    <!-- Referral bonus -->
                    <tr>
                        <th><?php esc_html_e( 'Πόντοι σύστασης (referrer)', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[referral_bonus]"
                                   value="<?php echo esc_attr( $s['referral_bonus'] ?? 400 ); ?>" min="0" class="small-text">
                        </td>
                    </tr>

                    <!-- Birthday bonus -->
                    <tr>
                        <th><?php esc_html_e( 'Πόντοι γενεθλίων', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[birthday_bonus]"
                                   value="<?php echo esc_attr( $s['birthday_bonus'] ?? 1500 ); ?>" min="0" class="small-text">
                        </td>
                    </tr>

                    <!-- Min redeem -->
                    <tr>
                        <th><?php esc_html_e( 'Ελάχιστοι πόντοι εξαργύρωσης', '2net-loyalty' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( TwoNet_Loyalty_Core::SETTINGS_KEY ); ?>[min_redeem_points]"
                                   value="<?php echo esc_attr( $s['min_redeem_points'] ?? 250 ); ?>" min="1" class="small-text">
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * User profile — points display & manual adjustment
     * ----------------------------------------------------------------*/

    public function show_user_points( $user ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $balance = TwoNet_Points_Manager::get_balance( $user->ID );
        ?>
        <h2><?php esc_html_e( '2NET Loyalty — Πόντοι', '2net-loyalty' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Τρέχον υπόλοιπο', '2net-loyalty' ); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong></td>
            </tr>
            <tr>
                <th>
                    <label for="2net_adjust_points"><?php esc_html_e( 'Χειροκίνητη προσαρμογή', '2net-loyalty' ); ?></label>
                </th>
                <td>
                    <input type="number" id="2net_adjust_points" name="2net_adjust_points" value="" class="small-text">
                    <p class="description"><?php esc_html_e( 'Θετικός αριθμός = πρόσθεση, αρνητικός = αφαίρεση.', '2net-loyalty' ); ?></p>
                </td>
            </tr>
            <tr>
                <th>
                    <label for="2net_adjust_reason"><?php esc_html_e( 'Αιτία', '2net-loyalty' ); ?></label>
                </th>
                <td>
                    <input type="text" id="2net_adjust_reason" name="2net_adjust_reason" value="" class="regular-text">
                </td>
            </tr>
        </table>
        <?php wp_nonce_field( '2net_adjust_points_' . $user->ID, '2net_adjust_nonce' ); ?>
        <?php
    }

    public function save_user_points_adjustment( $user_id ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( ! isset( $_POST['2net_adjust_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['2net_adjust_nonce'] ) ), '2net_adjust_points_' . $user_id ) ) {
            return;
        }

        $points = isset( $_POST['2net_adjust_points'] ) ? intval( $_POST['2net_adjust_points'] ) : 0;
        if ( 0 === $points ) {
            return;
        }

        $reason = isset( $_POST['2net_adjust_reason'] )
            ? sanitize_text_field( wp_unslash( $_POST['2net_adjust_reason'] ) )
            : __( 'Χειροκίνητη προσαρμογή από admin', '2net-loyalty' );

        if ( $points > 0 ) {
            TwoNet_Points_Manager::add_points( $user_id, $points, 'manual', 0, $reason );
        } else {
            TwoNet_Points_Manager::deduct_points( $user_id, abs( $points ), 'manual', 0, $reason );
        }
    }

    /* ------------------------------------------------------------------
     * AJAX: admin adjust points
     * ----------------------------------------------------------------*/

    public function ajax_adjust_points() {
        check_ajax_referer( '2net_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $points  = isset( $_POST['points'] ) ? intval( $_POST['points'] ) : 0;
        $reason  = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( ! $user_id || 0 === $points ) {
            wp_send_json_error( [ 'message' => 'Invalid input' ] );
        }

        if ( $points > 0 ) {
            $result = TwoNet_Points_Manager::add_points( $user_id, $points, 'manual', 0, $reason ?: __( 'Admin adjustment', '2net-loyalty' ) );
        } else {
            $result = TwoNet_Points_Manager::deduct_points( $user_id, abs( $points ), 'manual', 0, $reason ?: __( 'Admin adjustment', '2net-loyalty' ) );
        }

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => 'Failed to adjust points' ] );
        }

        wp_send_json_success( [ 'new_balance' => $result ] );
    }

    /* ------------------------------------------------------------------
     * Users list column
     * ----------------------------------------------------------------*/

    public function add_users_column( $columns ) {
        $columns['2net_points'] = __( 'Πόντοι Loyalty', '2net-loyalty' );
        return $columns;
    }

    public function render_users_column( $value, $column_name, $user_id ) {
        if ( '2net_points' !== $column_name ) {
            return $value;
        }
        return number_format_i18n( TwoNet_Points_Manager::get_balance( $user_id ) );
    }

    public function sortable_users_column( $columns ) {
        $columns['2net_points'] = '2net_points';
        return $columns;
    }

    public function sort_by_points( $query ) {
        if ( ! is_admin() || 'users' !== get_current_screen()->id ) {
            return;
        }

        if ( '2net_points' === $query->get( 'orderby' ) ) {
            $query->set( 'meta_key', TwoNet_Points_Manager::BALANCE_META );
            $query->set( 'orderby', 'meta_value_num' );
        }
    }

    /* ------------------------------------------------------------------
     * Admin assets
     * ----------------------------------------------------------------*/

    public function enqueue_admin_assets( $hook ) {
        if ( 'user-edit.php' === $hook || 'profile.php' === $hook ||
             str_contains( $hook, '2net-loyalty' ) ) {
            wp_enqueue_style(
                '2net-loyalty-admin',
                TWONET_LOYALTY_URL . 'assets/css/loyalty.css',
                [],
                TWONET_LOYALTY_VERSION
            );
        }
    }
}
