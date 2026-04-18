<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Products System
 *
 * Manages products that can be given as gifts/rewards to club members.
 * Members redeem points for physical or digital gift products.
 */
class EPC_Gifts {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_epc_add_gift', [ $this, 'ajax_add_gift' ] );
        add_action( 'wp_ajax_epc_update_gift', [ $this, 'ajax_update_gift' ] );
        add_action( 'wp_ajax_epc_delete_gift', [ $this, 'ajax_delete_gift' ] );
        add_action( 'wp_ajax_epc_toggle_gift', [ $this, 'ajax_toggle_gift' ] );
    }

    /**
     * Get all gift products, optionally filtered.
     */
    public static function get_all( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'active_only' => false,
            'tier'        => '',
            'per_page'    => 50,
            'page'        => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $params = [];

        if ( $args['active_only'] ) {
            $where .= ' AND is_active = 1';
        }

        if ( ! empty( $args['tier'] ) ) {
            $tiers = self::tier_hierarchy();
            $accessible = [];
            foreach ( $tiers as $t ) {
                $accessible[] = $t;
                if ( $t === $args['tier'] ) {
                    break;
                }
            }
            if ( ! empty( $accessible ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $accessible ), '%s' ) );
                $where .= " AND tier_required IN ({$placeholders})";
                $params = array_merge( $params, $accessible );
            }
        }

        $offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
        $limit  = (int) $args['per_page'];

        $query = "SELECT * FROM {$wpdb->prefix}epc_gift_products WHERE {$where} ORDER BY points_required ASC LIMIT {$limit} OFFSET {$offset}";

        if ( ! empty( $params ) ) {
            $query = $wpdb->prepare( $query, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL
        }

        return $wpdb->get_results( $query, ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /**
     * Get count of gift products.
     */
    public static function count( bool $active_only = false ): int {
        global $wpdb;
        $where = $active_only ? 'WHERE is_active = 1' : '';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_gift_products {$where}" ); // phpcs:ignore
    }

    /**
     * Get a single gift product by ID.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}epc_gift_products WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Create a gift product.
     */
    public static function create( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}epc_gift_products",
            [
                'product_id'      => ! empty( $data['product_id'] ) ? (int) $data['product_id'] : null,
                'title'           => sanitize_text_field( $data['title'] ?? '' ),
                'description'     => sanitize_textarea_field( $data['description'] ?? '' ),
                'points_required' => (int) ( $data['points_required'] ?? 0 ),
                'stock'           => (int) ( $data['stock'] ?? -1 ),
                'image_url'       => esc_url_raw( $data['image_url'] ?? '' ),
                'is_active'       => ! empty( $data['is_active'] ) ? 1 : 0,
                'tier_required'   => sanitize_text_field( $data['tier_required'] ?? 'basic' ),
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update a gift product.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $fields = [];
        $format = [];

        $allowed = [
            'product_id'      => '%d',
            'title'           => '%s',
            'description'     => '%s',
            'points_required' => '%d',
            'stock'           => '%d',
            'image_url'       => '%s',
            'is_active'       => '%d',
            'tier_required'   => '%s',
        ];

        foreach ( $allowed as $key => $fmt ) {
            if ( array_key_exists( $key, $data ) ) {
                $fields[ $key ] = $key === 'title' ? sanitize_text_field( $data[ $key ] )
                    : ( $key === 'description' ? sanitize_textarea_field( $data[ $key ] )
                    : ( $key === 'image_url' ? esc_url_raw( $data[ $key ] )
                    : $data[ $key ] ) );
                $format[] = $fmt;
            }
        }

        if ( empty( $fields ) ) {
            return false;
        }

        return (bool) $wpdb->update(
            "{$wpdb->prefix}epc_gift_products",
            $fields,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );
    }

    /**
     * Delete a gift product.
     */
    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            "{$wpdb->prefix}epc_gift_products",
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    /**
     * Redeem a gift for a member.
     */
    public static function redeem( int $member_id, int $gift_id ): array {
        global $wpdb;

        $gift = self::get( $gift_id );
        if ( ! $gift || ! $gift['is_active'] ) {
            return [ 'success' => false, 'message' => __( 'Το δώρο δεν είναι διαθέσιμο.', 'epappous-club' ) ];
        }

        if ( (int) $gift['stock'] === 0 ) {
            return [ 'success' => false, 'message' => __( 'Το δώρο έχει εξαντληθεί.', 'epappous-club' ) ];
        }

        $member = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}epc_members WHERE id = %d AND status = 'active'", $member_id ),
            ARRAY_A
        );

        if ( ! $member ) {
            return [ 'success' => false, 'message' => __( 'Μέλος δεν βρέθηκε.', 'epappous-club' ) ];
        }

        if ( ! EPC_B2BKing::member_id_in_pappou_club( $member_id ) ) {
            return [ 'success' => false, 'message' => __( 'Η εξαργύρωση είναι διαθέσιμη μόνο για την ομάδα Pappou Club (B2B King).', 'epappous-club' ) ];
        }

        // Check tier
        $tiers = self::tier_hierarchy();
        $member_tier_idx = array_search( $member['tier'], $tiers, true );
        $required_tier_idx = array_search( $gift['tier_required'], $tiers, true );
        if ( $member_tier_idx === false || $required_tier_idx === false || $member_tier_idx < $required_tier_idx ) {
            return [ 'success' => false, 'message' => __( 'Δεν έχετε αρκετό tier για αυτό το δώρο.', 'epappous-club' ) ];
        }

        // Check points
        $points_needed = (int) $gift['points_required'];
        if ( (int) $member['points'] < $points_needed ) {
            return [ 'success' => false, 'message' => __( 'Δεν έχετε αρκετούς πόντους.', 'epappous-club' ) ];
        }

        $wpdb->query( 'START TRANSACTION' );

        $points_updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = CAST(points AS SIGNED) - %d WHERE id = %d AND CAST(points AS SIGNED) >= %d",
                $points_needed,
                $member_id,
                $points_needed
            )
        );
        if ( 1 !== $points_updated ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'message' => __( 'Δεν έχετε αρκετούς πόντους.', 'epappous-club' ) ];
        }

        if ( (int) $gift['stock'] > 0 ) {
            $stock_updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_gift_products SET stock = stock - 1 WHERE id = %d AND stock > 0",
                    $gift_id
                )
            );
            if ( 1 !== $stock_updated ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'message' => __( 'Το δώρο έχει εξαντληθεί.', 'epappous-club' ) ];
            }
        }

        $log_inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => -$points_needed,
                'reason'         => 'gift_redemption',
                'reference_type' => 'gift',
                'reference_id'   => $gift_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $log_inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'message' => __( 'Αποτυχία καταγραφής εξαργύρωσης.', 'epappous-club' ) ];
        }

        $redemption_inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_gift_redemptions",
            [
                'member_id'       => $member_id,
                'gift_product_id' => $gift_id,
                'points_spent'    => $points_needed,
                'status'          => 'pending',
            ],
            [ '%d', '%d', '%d', '%s' ]
        );
        if ( false === $redemption_inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'message' => __( 'Αποτυχία καταχώρησης εξαργύρωσης.', 'epappous-club' ) ];
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_gift_redeemed', $member_id, $gift_id, (int) $wpdb->insert_id );

        return [ 'success' => true, 'message' => __( 'Το δώρο εξαργυρώθηκε επιτυχώς!', 'epappous-club' ) ];
    }

    /**
     * Ordered tier slugs from lowest to highest.
     */
    private static function tier_hierarchy(): array {
        $tiers_json = EPC_Settings::get( 'epc_tiers' );
        $tiers = json_decode( $tiers_json, true );
        if ( ! is_array( $tiers ) ) {
            return [ 'basic', 'silver', 'gold', 'platinum' ];
        }
        usort( $tiers, fn( $a, $b ) => ( $a['min_points'] ?? 0 ) <=> ( $b['min_points'] ?? 0 ) );
        return array_column( $tiers, 'slug' );
    }

    // ── AJAX handlers ──

    public function ajax_add_gift() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id = self::create( $_POST );
        if ( $id ) {
            wp_send_json_success( [ 'id' => $id, 'gift' => self::get( $id ) ] );
        }
        wp_send_json_error( 'Failed to create gift' );
    }

    public function ajax_update_gift() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id && self::update( $id, $_POST ) ) {
            wp_send_json_success( [ 'gift' => self::get( $id ) ] );
        }
        wp_send_json_error( 'Failed to update gift' );
    }

    public function ajax_delete_gift() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id && self::delete( $id ) ) {
            wp_send_json_success();
        }
        wp_send_json_error( 'Failed to delete gift' );
    }

    public function ajax_toggle_gift() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id = (int) ( $_POST['id'] ?? 0 );
        $gift = self::get( $id );
        if ( ! $gift ) {
            wp_send_json_error( 'Gift not found' );
        }

        self::update( $id, [ 'is_active' => $gift['is_active'] ? 0 : 1 ] );
        wp_send_json_success( [ 'gift' => self::get( $id ) ] );
    }
}
