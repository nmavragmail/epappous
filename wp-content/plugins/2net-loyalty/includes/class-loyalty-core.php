<?php
defined( 'ABSPATH' ) || exit;

/**
 * Core class — singleton, settings, DB schema, asset loading.
 */
class TwoNet_Loyalty_Core {

    private static $instance = null;

    const DB_VERSION     = '1.0.0';
    const SETTINGS_KEY   = '2net_loyalty_settings';
    const DB_VERSION_KEY = '2net_loyalty_db_version';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        add_action( 'admin_init', [ $this, 'maybe_upgrade_db' ] );
    }

    /* ------------------------------------------------------------------
     * Activation
     * ----------------------------------------------------------------*/

    public static function activate() {
        self::create_tables();
        self::set_default_settings();
        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;

        $table   = $wpdb->prefix . '2net_loyalty_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            points      INT            NOT NULL,
            type        VARCHAR(50)    NOT NULL DEFAULT 'manual',
            ref_id      BIGINT UNSIGNED DEFAULT NULL,
            description VARCHAR(255)   NOT NULL DEFAULT '',
            balance     INT            NOT NULL DEFAULT 0,
            created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_type    (type),
            KEY idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_KEY, self::DB_VERSION );
    }

    public static function set_default_settings() {
        $defaults = self::defaults();
        $existing = get_option( self::SETTINGS_KEY, [] );
        update_option( self::SETTINGS_KEY, wp_parse_args( $existing, $defaults ) );
    }

    public static function defaults() {
        return [
            'points_per_euro'           => 1,
            'redeem_points_amount'      => 250,
            'redeem_discount_value'     => 2,
            'coupon_points_cost'        => 125,
            'coupon_value'              => 2,
            'gift_category_id'          => 784,
            'welcome_coupon_percent'    => 20,
            'registration_bonus'        => 500,
            'referral_bonus'            => 400,
            'birthday_bonus'            => 1500,
            'min_redeem_points'         => 250,
            'enabled'                   => 'yes',
        ];
    }

    /* ------------------------------------------------------------------
     * Settings helpers
     * ----------------------------------------------------------------*/

    public static function get_setting( $key, $default = null ) {
        $settings = get_option( self::SETTINGS_KEY, [] );

        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }

        $defaults = self::defaults();
        return $default ?? ( $defaults[ $key ] ?? null );
    }

    public static function is_enabled() {
        return 'yes' === self::get_setting( 'enabled', 'yes' );
    }

    /* ------------------------------------------------------------------
     * DB Upgrade
     * ----------------------------------------------------------------*/

    public function maybe_upgrade_db() {
        $installed = get_option( self::DB_VERSION_KEY, '0' );
        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            self::create_tables();
        }
    }

    /* ------------------------------------------------------------------
     * Front-end assets
     * ----------------------------------------------------------------*/

    public function enqueue_frontend_assets() {
        if ( ! self::is_enabled() ) {
            return;
        }

        if ( is_account_page() || is_cart() || is_checkout() ) {
            wp_enqueue_style(
                '2net-loyalty',
                TWONET_LOYALTY_URL . 'assets/css/loyalty.css',
                [],
                TWONET_LOYALTY_VERSION
            );

            wp_enqueue_script(
                '2net-loyalty',
                TWONET_LOYALTY_URL . 'assets/js/loyalty.js',
                [ 'jquery' ],
                TWONET_LOYALTY_VERSION,
                true
            );

            wp_localize_script( '2net-loyalty', 'twonetLoyalty', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( '2net_loyalty_nonce' ),
                'i18n'     => [
                    'confirm_redeem'   => __( 'Θέλετε να εξαργυρώσετε τους πόντους σας;', '2net-loyalty' ),
                    'insufficient'     => __( 'Δεν έχετε αρκετούς πόντους.', '2net-loyalty' ),
                    'redeem_success'   => __( 'Η έκπτωση εφαρμόστηκε!', '2net-loyalty' ),
                    'error'            => __( 'Κάτι πήγε στραβά. Δοκιμάστε ξανά.', '2net-loyalty' ),
                ],
            ] );
        }
    }

    /* ------------------------------------------------------------------
     * Utility: get log table name
     * ----------------------------------------------------------------*/

    public static function log_table() {
        global $wpdb;
        return $wpdb->prefix . '2net_loyalty_log';
    }
}
