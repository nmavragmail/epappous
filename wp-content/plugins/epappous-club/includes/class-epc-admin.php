<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EPC_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menus() {
        add_menu_page(
            __( 'Pappou Club', 'epappous-club' ),
            __( 'Pappou Club', 'epappous-club' ),
            'manage_options',
            'epc-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-groups',
            30
        );

        add_submenu_page(
            'epc-dashboard',
            __( 'Ρυθμίσεις', 'epappous-club' ),
            __( 'Ρυθμίσεις', 'epappous-club' ),
            'manage_options',
            'epc-settings',
            [ $this, 'render_settings' ]
        );

        add_submenu_page(
            'epc-dashboard',
            __( 'Δώρα', 'epappous-club' ),
            __( 'Δώρα', 'epappous-club' ),
            'manage_options',
            'epc-gifts',
            [ $this, 'render_gifts' ]
        );

        add_submenu_page(
            'epc-dashboard',
            __( 'Referrals', 'epappous-club' ),
            __( 'Referrals', 'epappous-club' ),
            'manage_options',
            'epc-referrals',
            [ $this, 'render_referrals' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'epc-' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'epc-admin-css',
            EPC_PLUGIN_URL . 'admin/css/admin.css',
            [],
            EPC_VERSION
        );

        wp_enqueue_script(
            'epc-admin-js',
            EPC_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery', 'wp-color-picker' ],
            EPC_VERSION,
            true
        );

        wp_enqueue_style( 'wp-color-picker' );

        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        wp_localize_script( 'epc-admin-js', 'epcAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'epc_admin_nonce' ),
            'i18n'    => [
                'confirmDelete' => __( 'Είστε σίγουροι ότι θέλετε να διαγράψετε αυτό το δώρο;', 'epappous-club' ),
                'saved'         => __( 'Αποθηκεύτηκε!', 'epappous-club' ),
                'error'         => __( 'Σφάλμα!', 'epappous-club' ),
            ],
        ] );
    }

    public function render_dashboard() {
        include EPC_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    public function render_settings() {
        include EPC_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function render_gifts() {
        include EPC_PLUGIN_DIR . 'templates/admin-gifts.php';
    }

    public function render_referrals() {
        include EPC_PLUGIN_DIR . 'templates/admin-referrals.php';
    }
}
