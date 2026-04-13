<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Rules
 *
 * Manages rules that define which WooCommerce products are available as gifts.
 * Three rule types: individual product, product category, product tag.
 */
class EPC_Gift_Rules {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_epc_add_gift_rule', [ $this, 'ajax_add' ] );
        add_action( 'wp_ajax_epc_update_gift_rule', [ $this, 'ajax_update' ] );
        add_action( 'wp_ajax_epc_delete_gift_rule', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_epc_toggle_gift_rule', [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_epc_search_products', [ $this, 'ajax_search_products' ] );
    }

    /**
     * Get all rules.
     */
    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}epc_gift_rules ORDER BY rule_type, created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get a single rule.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}epc_gift_rules WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Resolve all active rules into WC product IDs with their gift config.
     * Returns: [ product_id => [ 'points_required' => X, 'tier_required' => Y ], ... ]
     */
    public static function resolve_products( string $member_tier = '' ): array {
        global $wpdb;

        $rules = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}epc_gift_rules WHERE is_active = 1",
            ARRAY_A
        );

        if ( empty( $rules ) ) {
            return [];
        }

        $products = [];

        foreach ( $rules as $rule ) {
            $product_ids = [];

            switch ( $rule['rule_type'] ) {
                case 'product':
                    $product_ids = [ (int) $rule['rule_value'] ];
                    break;

                case 'category':
                    $product_ids = self::get_products_by_taxonomy( 'product_cat', (int) $rule['rule_value'] );
                    break;

                case 'tag':
                    $product_ids = self::get_products_by_taxonomy( 'product_tag', (int) $rule['rule_value'] );
                    break;
            }

            foreach ( $product_ids as $pid ) {
                $product = wc_get_product( $pid );
                if ( ! $product || $product->get_status() !== 'publish' ) {
                    continue;
                }

                // If a product appears in multiple rules, use the one with lowest points
                if ( ! isset( $products[ $pid ] ) || (int) $rule['points_required'] < $products[ $pid ]['points_required'] ) {
                    $products[ $pid ] = [
                        'points_required' => (int) $rule['points_required'],
                        'tier_required'   => $rule['tier_required'],
                        'rule_id'         => (int) $rule['id'],
                    ];
                }
            }
        }

        // Filter by tier if provided
        if ( ! empty( $member_tier ) ) {
            $tiers = self::tier_hierarchy();
            $member_idx = array_search( $member_tier, $tiers, true );
            if ( $member_idx !== false ) {
                $products = array_filter( $products, function ( $cfg ) use ( $tiers, $member_idx ) {
                    $req_idx = array_search( $cfg['tier_required'], $tiers, true );
                    return $req_idx !== false && $member_idx >= $req_idx;
                } );
            }
        }

        return $products;
    }

    private static function get_products_by_taxonomy( string $taxonomy, int $term_id ): array {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
        ];
        return get_posts( $args ) ?: [];
    }

    private static function tier_hierarchy(): array {
        $tiers_json = EPC_Settings::get( 'epc_tiers' );
        $tiers = json_decode( $tiers_json, true );
        if ( ! is_array( $tiers ) ) {
            return [ 'basic', 'silver', 'gold', 'platinum' ];
        }
        usort( $tiers, fn( $a, $b ) => ( $a['min_points'] ?? 0 ) <=> ( $b['min_points'] ?? 0 ) );
        return array_column( $tiers, 'slug' );
    }

    /**
     * Get the human-readable label for a rule's value.
     */
    public static function get_rule_label( array $rule ): string {
        switch ( $rule['rule_type'] ) {
            case 'product':
                if ( function_exists( 'wc_get_product' ) ) {
                    $product = wc_get_product( (int) $rule['rule_value'] );
                    return $product ? $product->get_name() : '#' . $rule['rule_value'];
                }
                return '#' . $rule['rule_value'];

            case 'category':
                $term = get_term( (int) $rule['rule_value'], 'product_cat' );
                return $term && ! is_wp_error( $term ) ? $term->name : '#' . $rule['rule_value'];

            case 'tag':
                $term = get_term( (int) $rule['rule_value'], 'product_tag' );
                return $term && ! is_wp_error( $term ) ? $term->name : '#' . $rule['rule_value'];
        }
        return '#' . $rule['rule_value'];
    }

    // ── AJAX ──

    public function ajax_add() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;

        $type  = sanitize_text_field( $_POST['rule_type'] ?? '' );
        $value = (int) ( $_POST['rule_value'] ?? 0 );

        if ( ! in_array( $type, [ 'product', 'category', 'tag' ], true ) || $value < 1 ) {
            wp_send_json_error( 'Invalid rule' );
        }

        $wpdb->insert(
            "{$wpdb->prefix}epc_gift_rules",
            [
                'rule_type'       => $type,
                'rule_value'      => $value,
                'points_required' => (int) ( $_POST['points_required'] ?? 0 ),
                'tier_required'   => sanitize_text_field( $_POST['tier_required'] ?? 'basic' ),
                'is_active'       => 1,
            ],
            [ '%s', '%d', '%d', '%s', '%d' ]
        );

        wp_send_json_success( [ 'id' => (int) $wpdb->insert_id ] );
    }

    public function ajax_update() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id < 1 ) {
            wp_send_json_error( 'Missing id' );
        }

        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}epc_gift_rules",
            [
                'points_required' => (int) ( $_POST['points_required'] ?? 0 ),
                'tier_required'   => sanitize_text_field( $_POST['tier_required'] ?? 'basic' ),
            ],
            [ 'id' => $id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        wp_send_json_success();
    }

    public function ajax_delete() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id < 1 ) {
            wp_send_json_error();
        }

        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}epc_gift_rules", [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    public function ajax_toggle() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id   = (int) ( $_POST['id'] ?? 0 );
        $rule = self::get( $id );
        if ( ! $rule ) {
            wp_send_json_error();
        }

        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}epc_gift_rules",
            [ 'is_active' => $rule['is_active'] ? 0 : 1 ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );
        wp_send_json_success();
    }

    /**
     * AJAX: search WooCommerce products for Select2.
     */
    public function ajax_search_products() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $q = sanitize_text_field( $_GET['q'] ?? $_POST['q'] ?? '' );
        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( [] );
        }

        $products = wc_get_products( [
            'limit'  => 20,
            'status' => 'publish',
            's'      => $q,
            'return' => 'objects',
        ] );

        $results = [];
        foreach ( $products as $p ) {
            $results[] = [
                'id'   => $p->get_id(),
                'text' => $p->get_name() . ' (#' . $p->get_id() . ')',
            ];
        }

        wp_send_json_success( $results );
    }
}
