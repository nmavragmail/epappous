<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EPC_Settings {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    /**
     * Cached defaults so we don't rebuild the array (with multiple
     * wp_json_encode calls) on every EPC_Settings::get() invocation.
     */
    private static $defaults_cache = null;

    /**
     * All default option values in a single place.
     */
    public static function defaults() {
        if ( null !== self::$defaults_cache ) {
            return self::$defaults_cache;
        }

        self::$defaults_cache = [
            // ── General ──
            'epc_club_name'            => 'Pappou Club',
            'epc_club_enabled'         => '1',
            'epc_currency_label'       => 'πόντοι',
            'epc_currency_symbol'      => '★',
            'epc_min_age'              => '0',
            'epc_terms_page_id'        => '',
            'epc_privacy_page_id'      => '',

            // ── Points ──
            'epc_points_per_euro'      => '1',
            'epc_points_value_euro'    => '0.01',
            'epc_min_redeem_points'    => '100',
            'epc_max_redeem_percent'   => '50',
            'epc_points_expiry_days'   => '365',
            'epc_birthday_bonus'       => '50',
            'epc_signup_bonus_points'  => '0',

            // ── Tiers ──
            'epc_tiers'                => wp_json_encode( [
                [
                    'slug'       => 'basic',
                    'label'      => 'Basic',
                    'min_points' => 0,
                    'multiplier' => 1.0,
                    'color'      => '#6b7280',
                ],
                [
                    'slug'       => 'silver',
                    'label'      => 'Silver',
                    'min_points' => 500,
                    'multiplier' => 1.5,
                    'color'      => '#9ca3af',
                ],
                [
                    'slug'       => 'gold',
                    'label'      => 'Gold',
                    'min_points' => 2000,
                    'multiplier' => 2.0,
                    'color'      => '#f59e0b',
                ],
                [
                    'slug'       => 'platinum',
                    'label'      => 'Platinum',
                    'min_points' => 5000,
                    'multiplier' => 3.0,
                    'color'      => '#8b5cf6',
                ],
            ] ),

            // ── Referral ──
            'epc_referral_enabled'              => '1',
            'epc_referral_reward_referrer'      => '100',
            'epc_referral_reward_referred'      => '50',
            'epc_referral_reward_type'          => 'points',
            'epc_referral_require_purchase'     => '0',
            'epc_referral_min_order'            => '0',
            'epc_referral_max_referrals'        => '0',
            'epc_referral_cookie_days'          => '30',
            'epc_referral_code_prefix'          => 'PAPPOU',
            'epc_referral_track_membership'     => '1',
            'epc_referral_track_purchase'       => '1',

            // ── Gift Products ──
            'epc_gifts_enabled'        => '1',
            'epc_gifts_min_tier'       => 'basic',
            'epc_gifts_per_page'       => '12',
            'epc_gifts_show_stock'     => '1',

            // ── Notifications ──
            'epc_notify_new_member'         => '1',
            'epc_notify_referral_complete'  => '1',
            'epc_notify_gift_redeemed'      => '1',
            'epc_notify_tier_upgrade'       => '1',
            'epc_admin_email'               => '',

            // ── WooCommerce integration ──
            'epc_woo_earn_on_complete'      => '1',
            'epc_woo_earn_statuses'         => wp_json_encode( [ 'completed' ] ),
            'epc_woo_exclude_sale_items'    => '0',
            'epc_woo_exclude_categories'    => wp_json_encode( [] ),
            'epc_woo_earn_include_shipping' => '0',

            // ── WooCommerce gift products (purchased with points only) ──
            'epc_woo_gift_category'             => '0',
            'epc_woo_gift_allow_redeem_stack'   => '1',

            // ── B2B King (Pappou Club = this group ID; changes on site migration) ──
            'epc_b2bking_club_group_id'     => '1446',
        ];

        return self::$defaults_cache;
    }

    /**
     * Get a single setting with fallback to default.
     */
    public static function get( string $key, $fallback = null ) {
        if ( null !== $fallback ) {
            return get_option( $key, $fallback );
        }
        $defaults = self::defaults();
        $default  = $defaults[ $key ] ?? '';
        return get_option( $key, $default );
    }

    /**
     * Register all setting fields via the Settings API.
     */
    public function register_settings() {
        $settings = [
            // General
            'epc_club_name', 'epc_club_enabled', 'epc_currency_label',
            'epc_currency_symbol', 'epc_min_age', 'epc_terms_page_id',
            'epc_privacy_page_id',
            // Points
            'epc_points_per_euro', 'epc_points_value_euro', 'epc_min_redeem_points',
            'epc_max_redeem_percent', 'epc_points_expiry_days', 'epc_birthday_bonus',
            // Tiers
            'epc_tiers',
            // Referral
            'epc_referral_enabled', 'epc_referral_reward_referrer',
            'epc_referral_reward_referred', 'epc_referral_reward_type',
            'epc_referral_require_purchase', 'epc_referral_min_order',
            'epc_referral_max_referrals', 'epc_referral_cookie_days',
            'epc_referral_code_prefix', 'epc_referral_track_membership',
            'epc_referral_track_purchase',
            // Gifts
            'epc_gifts_enabled', 'epc_gifts_min_tier', 'epc_gifts_per_page',
            'epc_gifts_show_stock',
            // Notifications
            'epc_notify_new_member', 'epc_notify_referral_complete',
            'epc_notify_gift_redeemed', 'epc_notify_tier_upgrade', 'epc_admin_email',
            // WooCommerce
            'epc_woo_earn_on_complete', 'epc_woo_earn_statuses',
            'epc_woo_exclude_sale_items', 'epc_woo_exclude_categories',
            'epc_woo_earn_include_shipping',
            'epc_woo_gift_category', 'epc_woo_gift_allow_redeem_stack',
        ];

        foreach ( $settings as $key ) {
            register_setting( 'epc_settings_group', $key, [
                'sanitize_callback' => [ $this, 'sanitize_setting' ],
            ] );
        }

        register_setting(
            'epc_settings_group',
            'epc_b2bking_club_group_id',
            [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 1446,
            ]
        );

        register_setting(
            'epc_settings_group',
            'epc_signup_bonus_points',
            [
                'sanitize_callback' => [ self::class, 'sanitize_non_negative_int' ],
                'default'           => '0',
            ]
        );
    }

    public function sanitize_setting( $value ) {
        if ( is_string( $value ) ) {
            return sanitize_text_field( $value );
        }
        return $value;
    }

    /**
     * Sanitize non-negative integer options (used by settings form callbacks).
     */
    public static function sanitize_non_negative_int( $value ): string {
        return (string) max( 0, absint( $value ) );
    }
}
