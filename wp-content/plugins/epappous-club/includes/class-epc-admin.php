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
            __( 'Μέλη', 'epappous-club' ),
            __( 'Μέλη', 'epappous-club' ),
            'manage_options',
            'epc-members',
            [ $this, 'render_members' ]
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

        add_submenu_page(
            'epc-dashboard',
            __( 'Ιστορικό Πόντων', 'epappous-club' ),
            __( 'Ιστορικό Πόντων', 'epappous-club' ),
            'manage_options',
            'epc-points-log',
            [ $this, 'render_points_log' ]
        );
    }

    public function enqueue_assets( $hook ) {
        $is_epc_page    = strpos( $hook, 'epc-' ) !== false;
        $is_profile     = in_array( $hook, [ 'profile.php', 'user-edit.php' ], true );

        if ( ! $is_epc_page && ! $is_profile ) {
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

    public function render_members() {
        include EPC_PLUGIN_DIR . 'templates/admin-members.php';
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

    public function render_points_log() {
        include EPC_PLUGIN_DIR . 'templates/admin-points-log.php';
    }
}
