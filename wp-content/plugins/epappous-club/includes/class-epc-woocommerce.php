<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Integration
 *
 * - Earn points on order completion (tier multiplier disabled site-wide; sale/category exclusions)
 * - Redeem points as cart discount at checkout
 * - Optional club sign-up at checkout (account required; DOB for birthday bonus)
 */
class EPC_WooCommerce {

    /** Block checkout additional field IDs (WooCommerce 8.9+). */
    const BLOCK_FIELD_JOIN = 'epappous-club/join';
    const BLOCK_FIELD_DOB  = 'epappous-club/dob';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Earn/revoke points on order status transitions.
        add_action( 'woocommerce_order_status_processing', [ $this, 'earn_points_on_order' ], 20 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'earn_points_on_order' ], 20 );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'revoke_points_on_order' ], 20 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'revoke_points_on_order' ], 20 );

        // Redeem points at checkout
        add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'render_redeem_ui' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_redeem_ui' ] );
        add_action( 'wp_ajax_epc_apply_points_discount', [ $this, 'ajax_apply_discount' ] );
        add_action( 'wp_ajax_epc_remove_points_discount', [ $this, 'ajax_remove_discount' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_points_fee' ] );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'record_points_redemption' ], 20 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'record_points_redemption_from_blocks' ], 20, 2 );
        if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.2.0', '<' ) ) {
            add_action( '__experimental_woocommerce_blocks_checkout_update_order_from_request', [ $this, 'record_points_redemption_from_blocks' ], 20, 2 );
        }

        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_club_loyalty_order_summary' ], 12, 1 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_cassette_gift_order_panel' ], 15, 1 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_checkout_club_order_meta' ], 16, 1 );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_js' ] );

        // Checkout club sign-up (classic + blocks where API exists).
        add_action( 'woocommerce_init', [ $this, 'register_block_checkout_club_fields' ], 30 );
        add_action( 'woocommerce_after_order_notes', [ $this, 'render_checkout_club_fields' ], 10, 1 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_club_fields' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_checkout_club_fields_to_order' ], 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'maybe_register_club_member_from_order' ], 40, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'maybe_register_club_member_from_order_blocks' ], 40, 1 );

        add_action( 'woocommerce_blocks_validate_location_other_fields', [ $this, 'validate_block_checkout_club_fields' ], 10, 3 );

        // WooCommerce emails: show earned points in new order emails.
        add_action( 'woocommerce_email_after_order_table', [ $this, 'email_add_points_earned_line' ], 15, 4 );
    }

    /**
     * Show cassette gift status on order screen when the customer has user meta flag (Pappou Club).
     */
    public function render_cassette_gift_order_panel( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id < 1 ) {
            $email = $order->get_billing_email();
            if ( $email ) {
                $u = get_user_by( 'email', $email );
                if ( $u ) {
                    $user_id = (int) $u->ID;
                }
            }
        }

        if ( $user_id < 1 ) {
            return;
        }

        if ( get_user_meta( $user_id, 'epc_cassette_gift_received', true ) !== 'yes' ) {
            return;
        }

        $raw = get_user_meta( $user_id, 'epc_cassette_gift_date', true );
        $date_display = '';
        if ( $raw ) {
            $ts = strtotime( $raw . ' 12:00:00' );
            $date_display = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
        }

        $audit = EPC_User_Profile::get_cassette_audit_for_display( $user_id );

        ?>
        <div class="epc-order-cassette-gift card" style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;clear:both;">
            <strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'ePappous Club — Δώρο κασετίνα', 'epappous-club' ); ?></strong>
            <p style="margin:0;">
                <?php esc_html_e( 'Έχει πάρει δώρο κασετίνα:', 'epappous-club' ); ?>
                <strong><?php esc_html_e( 'Ναι', 'epappous-club' ); ?></strong>
                <?php if ( $date_display !== '' ) : ?>
                    — <?php esc_html_e( 'Ημερομηνία δώρου:', 'epappous-club' ); ?> <strong><?php echo esc_html( $date_display ); ?></strong>
                <?php else : ?>
                    — <?php esc_html_e( 'Ημερομηνία δώρου:', 'epappous-club' ); ?> <em><?php esc_html_e( 'δεν έχει οριστεί', 'epappous-club' ); ?></em>
                <?php endif; ?>
            </p>
            <?php if ( $audit ) : ?>
                <p style="margin:8px 0 0;font-size:12px;color:#50575e;">
                    <?php
                    printf(
                        /* translators: 1: admin name, 2: datetime */
                        esc_html__( 'Καταχώρηση πεδίου: %1$s — %2$s', 'epappous-club' ),
                        esc_html( $audit['editor_name'] ),
                        esc_html( $audit['edited_at'] )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * WooCommerce emails: show points earned for this order.
     *
     * @param \WC_Order $order         Order.
     * @param bool      $sent_to_admin Whether email is sent to admin.
     * @param bool      $plain_text    Plain text email.
     * @param \WC_Email $email         Email instance.
     */
    public function email_add_points_earned_line( $order, $sent_to_admin, $plain_text, $email ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        if ( ! $email instanceof \WC_Email ) {
            return;
        }

        $allowed_ids = [ 'new_order', 'customer_processing_order' ];
        if ( ! in_array( (string) $email->id, $allowed_ids, true ) ) {
            return;
        }

        // If points haven't been awarded yet, show 0/skip instead of forcing DB writes from email hook.
        $points = $this->get_points_earned_for_order_display( $order );
        if ( null === $points ) {
            return;
        }

        $label = __( 'Πόντοι από αυτή την παραγγελία', 'epappous-club' );
        $value = (string) (int) $points;

        if ( $plain_text ) {
            echo "\n" . $label . ': ' . $value . "\n";
            return;
        }

        echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>';
    }

    /**
     * Returns points earned for display in emails.
     * - If order meta exists, use it.
     * - If not settled yet (rare), compute safely without persisting.
     *
     * @return int|null null means "do not show".
     */
    private function get_points_earned_for_order_display( \WC_Order $order ): ?int {
        $meta = $order->get_meta( '_epc_points_earned', true );
        if ( '' !== (string) $meta ) {
            return (int) $meta;
        }

        // Only show for statuses where points can apply.
        $status = (string) $order->get_status();
        if ( ! in_array( $status, [ 'processing', 'completed' ], true ) ) {
            return null;
        }

        if ( ! EPC_B2BKing::order_customer_in_pappou_club( $order ) ) {
            return 0;
        }

        global $wpdb;
        $email = $order->get_billing_email();
        if ( ! is_email( $email ) ) {
            return 0;
        }

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tier FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active' LIMIT 1",
                $email
            ),
            ARRAY_A
        );
        if ( ! $member ) {
            return 0;
        }

        $points_per_euro   = (float) EPC_Settings::get( 'epc_points_per_euro' );
        $exclude_sale      = EPC_Settings::get( 'epc_woo_exclude_sale_items' ) === '1';
        $exclude_cats_json = EPC_Settings::get( 'epc_woo_exclude_categories' );
        $exclude_cats      = json_decode( $exclude_cats_json, true ) ?: [];

        $eligible_total = 0.0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            if ( $exclude_sale && $product->is_on_sale() ) {
                continue;
            }
            if ( ! empty( $exclude_cats ) ) {
                $lookup_id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
                $product_cats = wp_get_post_terms( $lookup_id, 'product_cat', [ 'fields' => 'ids' ] );
                if ( array_intersect( $product_cats, $exclude_cats ) ) {
                    continue;
                }
            }
            $eligible_total += (float) $item->get_total();
        }

        $raw_points = $eligible_total * $points_per_euro;
        $points     = (int) floor( $raw_points * 1.0 );

        return max( 0, $points );
    }

    /**
     * Award points when an order is processing/completed; persist order meta for admin / exports.
     */
    public function earn_points_on_order( $order_id ) {
        if ( EPC_Settings::get( 'epc_woo_earn_on_complete' ) !== '1' ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_epc_club_loyalty_settled', true ) === '1' ) {
            return;
        }

        $in_club = EPC_B2BKing::order_customer_in_pappou_club( $order );

        global $wpdb;
        $email  = $order->get_billing_email();
        $member = null;
        if ( is_email( $email ) ) {
            $member = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active'",
                    $email
                ),
                ARRAY_A
            );
        }

        if ( ! $in_club || ! $member ) {
            $order->update_meta_data( '_epc_club_loyalty_settled', '1' );
            $order->update_meta_data( '_epc_order_includes_club_gift_product', 'n/a' );
            $order->save();
            return;
        }

        $gift_catalog = EPC_Gift_Rules::resolve_products( (string) ( $member['tier'] ?? 'basic' ) );
        $gift_line    = $this->order_contains_catalog_gift_product( $order, $gift_catalog );

        $points_per_euro   = (float) EPC_Settings::get( 'epc_points_per_euro' );
        $exclude_sale      = EPC_Settings::get( 'epc_woo_exclude_sale_items' ) === '1';
        $exclude_cats_json = EPC_Settings::get( 'epc_woo_exclude_categories' );
        $exclude_cats      = json_decode( $exclude_cats_json, true ) ?: [];

        $tier_multiplier = 1.0;
        $eligible_total  = 0;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            if ( $exclude_sale && $product->is_on_sale() ) {
                continue;
            }

            if ( ! empty( $exclude_cats ) ) {
                $lookup_id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
                $product_cats = wp_get_post_terms( $lookup_id, 'product_cat', [ 'fields' => 'ids' ] );
                if ( array_intersect( $product_cats, $exclude_cats ) ) {
                    continue;
                }
            }

            $eligible_total += (float) $item->get_total();
        }

        $raw_points = $eligible_total * $points_per_euro;
        $points     = (int) floor( $raw_points * $tier_multiplier );

        if ( $eligible_total <= 0 || $points < 1 ) {
            try {
                $order->update_meta_data( '_epc_points_earned', 0 );
                $order->update_meta_data( '_epc_club_loyalty_settled', '1' );
                $order->update_meta_data( '_epc_order_includes_club_gift_product', $gift_line ? 'yes' : 'no' );
                $order->save();
            } catch ( Throwable $e ) {
                return;
            }
            return;
        }

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                $points,
                (int) $member['id']
            )
        );
        if ( 1 !== $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => (int) $member['id'],
                'points'         => $points,
                'reason'         => 'order_earning',
                'reference_type' => 'order',
                'reference_id'   => $order_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        try {
            $order->update_meta_data( '_epc_points_earned', $points );
            $order->update_meta_data( '_epc_club_loyalty_settled', '1' );
            $order->update_meta_data( '_epc_order_includes_club_gift_product', $gift_line ? 'yes' : 'no' );
            $order->save();
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_points_changed', (int) $member['id'] );
    }

    /**
     * Revoke previously awarded order points when order is cancelled/refunded.
     */
    public function revoke_points_on_order( $order_id ) {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( '_epc_points_revoked', true ) === '1' ) {
            return;
        }

        $awarded_points = (int) $order->get_meta( '_epc_points_earned', true );
        if ( $awarded_points < 1 ) {
            $order->update_meta_data( '_epc_points_revoked', '1' );
            $order->save();
            return;
        }

        global $wpdb;
        $email = sanitize_email( (string) $order->get_billing_email() );
        if ( ! is_email( $email ) ) {
            return;
        }

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE email = %s LIMIT 1",
                $email
            ),
            ARRAY_A
        );
        if ( ! $member ) {
            return;
        }

        $member_id       = (int) $member['id'];
        $current_points  = (int) $member['points'];
        $points_to_revoke = min( $awarded_points, max( 0, $current_points ) );

        $wpdb->query( 'START TRANSACTION' );

        if ( $points_to_revoke > 0 ) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_members SET points = points - %d WHERE id = %d AND points >= %d",
                    $points_to_revoke,
                    $member_id,
                    $points_to_revoke
                )
            );
            if ( 1 !== $updated ) {
                $wpdb->query( 'ROLLBACK' );
                return;
            }

            $inserted = $wpdb->insert(
                "{$wpdb->prefix}epc_points_log",
                [
                    'member_id'      => $member_id,
                    'points'         => -$points_to_revoke,
                    'reason'         => 'order_reversal',
                    'reference_type' => 'order',
                    'reference_id'   => (int) $order_id,
                ],
                [ '%d', '%d', '%s', '%s', '%d' ]
            );
            if ( false === $inserted ) {
                $wpdb->query( 'ROLLBACK' );
                return;
            }
        }

        try {
            $order->update_meta_data( '_epc_points_revoked', '1' );
            $order->update_meta_data( '_epc_points_revoked_amount', $points_to_revoke );
            $order->save();
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $wpdb->query( 'COMMIT' );
        do_action( 'epc_points_changed', $member_id );
    }

    /**
     * True if any line item is a product that appears in the active gift catalog (rules).
     *
     * @param \WC_Order              $order         Order.
     * @param array<int,array>       $gift_catalog Keys = WC product IDs from EPC_Gift_Rules::resolve_products().
     */
    private function order_contains_catalog_gift_product( \WC_Order $order, array $gift_catalog ): bool {
        if ( empty( $gift_catalog ) ) {
            return false;
        }
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $ids = [ (int) $product->get_id() ];
            if ( $product->is_type( 'variation' ) ) {
                $ids[] = (int) $product->get_parent_id();
            }
            foreach ( array_unique( array_filter( $ids ) ) as $pid ) {
                if ( isset( $gift_catalog[ $pid ] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * WooCommerce admin: summary of Club loyalty data stored on this order.
     *
     * @param \WC_Order|false $order Order.
     */
    public function render_club_loyalty_order_summary( $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $settled       = $order->get_meta( '_epc_club_loyalty_settled', true ) === '1';
        $earned_meta   = $order->get_meta( '_epc_points_earned', true );
        $redeem        = $order->get_meta( '_epc_points_redeemed', true );
        $disc          = $order->get_meta( '_epc_discount_amount', true );
        $gift          = (string) $order->get_meta( '_epc_order_includes_club_gift_product', true );
        $revoked_meta  = $order->get_meta( '_epc_points_revoked_amount', true );

        if ( ! $settled && '' === (string) $earned_meta && '' === (string) $redeem && '' === $gift ) {
            return;
        }

        if ( $settled ) {
            $earned_display = (string) (int) $order->get_meta( '_epc_points_earned', true );
        } elseif ( '' !== (string) $earned_meta ) {
            $earned_display = (string) (int) $earned_meta;
        } else {
            $earned_display = '—';
        }

        $gift_labels = [
            'yes' => __( 'Ναι — περιλαμβάνει προϊόν από τον κατάλογο δώρων του Club (WC γραμμή παραγγελίας).', 'epappous-club' ),
            'no'  => __( 'Όχι — δεν εντοπίστηκε προϊόν καταλόγου δώρων στις γραμμές της παραγγελίας.', 'epappous-club' ),
            'n/a' => __( 'Δεν εφαρμόζεται (όχι μέλος ή όχι σε ομάδα Club / χωρίς εκτίμηση).', 'epappous-club' ),
        ];
        $gift_text = $gift_labels[ $gift ] ?? '—';

        ?>
        <div class="address" style="clear:both;margin-top:12px;padding:12px;background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;">
            <p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Pappou Club — πόντοι & δώρο (μετα-δεδομένα παραγγελίας)', 'epappous-club' ); ?></strong></p>
            <p style="margin:4px 0;font-size:13px;">
                <?php esc_html_e( 'Πόντοι από αυτή την παραγγελία (processing/completed):', 'epappous-club' ); ?>
                <strong><?php echo esc_html( $earned_display ); ?></strong>
            </p>
            <p style="margin:4px 0;font-size:13px;">
                <?php esc_html_e( 'Πόντοι που εξαργυρώθηκαν στο checkout:', 'epappous-club' ); ?>
                <strong><?php echo '' !== (string) $redeem ? esc_html( (string) (int) $redeem ) : '—'; ?></strong>
                <?php if ( '' !== (string) $disc ) : ?>
                    <?php
                    printf(
                        ' — %1$s %2$s €',
                        esc_html__( 'έκπτωση', 'epappous-club' ),
                        esc_html( wc_format_decimal( (float) $disc, 2 ) )
                    );
                    ?>
                <?php endif; ?>
            </p>
            <p style="margin:4px 0;font-size:13px;">
                <?php esc_html_e( 'Πόντοι που αφαιρέθηκαν λόγω cancelled/refunded:', 'epappous-club' ); ?>
                <strong><?php echo '' !== (string) $revoked_meta ? esc_html( (string) (int) $revoked_meta ) : '—'; ?></strong>
            </p>
            <p style="margin:4px 0;font-size:13px;">
                <?php esc_html_e( 'Γραμμή παραγγελίας με προϊόν από κατάλογο δώρων Club:', 'epappous-club' ); ?>
                <strong><?php echo esc_html( $gift_text ); ?></strong>
            </p>
            <p style="margin:8px 0 0;font-size:11px;color:#50575e;">
                <?php esc_html_e( 'Η εξαργύρωση δώρου από τη σελίδα δώρων (χωρίς παραγγελία WooCommerce) καταγράφεται στις εγγραφές εξαργύρωσης του plugin, όχι σε μετα-δεδομένα παραγγελίας.', 'epappous-club' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the "Use points" UI in the cart/checkout totals.
     */
    public function render_redeem_ui() {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }

        global $wpdb;
        $user   = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            )
        );

        if ( ! $member ) {
            return;
        }

        if ( ! EPC_B2BKing::user_in_pappou_club( (int) $user->ID ) ) {
            return;
        }

        $min_points   = (int) EPC_Settings::get( 'epc_min_redeem_points' );
        $point_value  = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $max_percent  = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
        $member_pts   = (int) $member->points;
        $currency     = EPC_Settings::get( 'epc_currency_label' );

        if ( $member_pts < $min_points ) {
            return;
        }

        $cart_total    = (float) WC()->cart->get_subtotal();
        $max_discount  = $cart_total * ( $max_percent / 100 );
        $max_from_pts  = $member_pts * $point_value;
        $max_usable    = min( $max_discount, $max_from_pts );
        $points_needed = (int) floor( $max_usable / $point_value );

        $already_applied = WC()->session->get( 'epc_points_discount', 0 );
        ?>
        <tr class="epc-checkout-redeem">
            <th><?php echo esc_html( EPC_Settings::get( 'epc_club_name' ) ); ?></th>
            <td>
                <?php if ( $already_applied > 0 ) : ?>
                    <span class="epc-checkout-applied">
                        <?php printf(
                            esc_html__( 'Χρησιμοποιείτε %d %s (-%s)', 'epappous-club' ),
                            (int) WC()->session->get( 'epc_points_used', 0 ),
                            esc_html( $currency ),
                            wc_price( $already_applied )
                        ); ?>
                    </span>
                    <button type="button" class="epc-remove-points-btn" style="margin-left:8px;cursor:pointer;color:#ef4444;background:none;border:none;font-size:12px;">
                        <?php esc_html_e( 'Αφαίρεση', 'epappous-club' ); ?>
                    </button>
                <?php else : ?>
                    <span class="epc-checkout-info">
                        <?php printf(
                            esc_html__( 'Έχεις %d %s (αξία: %s, μέγιστο: %s)', 'epappous-club' ),
                            $member_pts,
                            esc_html( $currency ),
                            wc_price( $max_from_pts ),
                            wc_price( $max_usable )
                        ); ?>
                    </span>
                    <br />
                    <button type="button" class="epc-apply-points-btn button" data-max="<?php echo (int) $points_needed; ?>">
                        <?php printf( esc_html__( 'Χρήση %d %s', 'epappous-club' ), $points_needed, esc_html( $currency ) ); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * AJAX: apply points discount to session.
     */
    public function ajax_apply_discount() {
        check_ajax_referer( 'epc_front_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error();
        }

        global $wpdb;
        $user   = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            )
        );

        if ( ! $member ) {
            wp_send_json_error();
        }

        if ( ! EPC_B2BKing::user_in_pappou_club( (int) $user->ID ) ) {
            wp_send_json_error();
        }

        $point_value  = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $max_percent  = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
        $cart_total   = (float) WC()->cart->get_subtotal();
        $max_discount = $cart_total * ( $max_percent / 100 );
        $max_from_pts = (int) $member->points * $point_value;
        $discount     = min( $max_discount, $max_from_pts );
        $pts_used     = (int) floor( $discount / $point_value );

        WC()->session->set( 'epc_points_discount', $discount );
        WC()->session->set( 'epc_points_used', $pts_used );
        WC()->session->set( 'epc_points_member_id', (int) $member->id );

        wp_send_json_success();
    }

    /**
     * AJAX: remove points discount.
     */
    public function ajax_remove_discount() {
        check_ajax_referer( 'epc_front_nonce', 'nonce' );

        WC()->session->set( 'epc_points_discount', 0 );
        WC()->session->set( 'epc_points_used', 0 );
        WC()->session->set( 'epc_points_member_id', 0 );

        wp_send_json_success();
    }

    /**
     * Apply session-stored discount as a negative fee.
     */
    public function apply_points_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $discount = (float) WC()->session->get( 'epc_points_discount', 0 );
        if ( $discount > 0 ) {
            if ( is_user_logged_in() && ! EPC_B2BKing::user_in_pappou_club( (int) get_current_user_id() ) ) {
                $this->clear_points_discount_session();
                return;
            }

            $max_percent   = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
            $cart_subtotal = (float) $cart->get_subtotal();
            $max_discount  = $cart_subtotal * ( $max_percent / 100 );
            $discount      = min( $discount, $max_discount );

            if ( $discount <= 0 ) {
                $this->clear_points_discount_session();
                return;
            }

            $label = sprintf(
                __( 'Έκπτωση %s', 'epappous-club' ),
                EPC_Settings::get( 'epc_club_name' )
            );
            $cart->add_fee( $label, -$discount );
        }
    }

    /**
     * Deduct points from member when order is placed with points discount.
     */
    public function record_points_redemption( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_epc_points_redeemed', true ) ) {
            $this->clear_points_discount_session();
            return;
        }

        $discount  = (float) WC()->session->get( 'epc_points_discount', 0 );
        $pts_used  = (int) WC()->session->get( 'epc_points_used', 0 );
        $member_id = (int) WC()->session->get( 'epc_points_member_id', 0 );

        if ( $discount <= 0 || $pts_used < 1 || $member_id < 1 ) {
            return;
        }

        $this->commit_points_redemption( $order, $member_id, $pts_used, $discount );
        $this->clear_points_discount_session();
    }

    /**
     * Persist block-checkout points redemption data on order creation.
     */
    public function record_points_redemption_from_blocks( $order, $request = null ) {
        if ( ! $order ) {
            $this->clear_points_discount_session();
            return;
        }

        if ( $order->get_meta( '_epc_points_redeemed', true ) ) {
            $this->clear_points_discount_session();
            return;
        }

        $extensions = [];
        if ( $request instanceof \WP_REST_Request ) {
            $ext = $request->get_param( 'extensions' );
            $extensions = is_array( $ext ) ? $ext : [];
        } elseif ( is_array( $request ) && isset( $request['extensions'] ) && is_array( $request['extensions'] ) ) {
            $extensions = $request['extensions'];
        }
        $payload = is_array( $extensions['epappous-club'] ?? null ) ? $extensions['epappous-club'] : [];

        $member_id = isset( $payload['member_id'] ) ? (int) $payload['member_id'] : (int) WC()->session->get( 'epc_points_member_id', 0 );
        $pts_used  = isset( $payload['points_used'] ) ? (int) $payload['points_used'] : (int) WC()->session->get( 'epc_points_used', 0 );
        $discount  = isset( $payload['discount'] ) ? (float) $payload['discount'] : (float) WC()->session->get( 'epc_points_discount', 0 );

        if ( $discount <= 0 || $pts_used < 1 || $member_id < 1 ) {
            return;
        }

        $this->commit_points_redemption( $order, $member_id, $pts_used, $discount );
        $this->clear_points_discount_session();
    }

    /**
     * Apply idempotent points deduction and log entry.
     */
    private function commit_points_redemption( $order, $member_id, $pts_used, $discount ) {
        if ( ! EPC_B2BKing::order_customer_in_pappou_club( $order ) ) {
            $this->clear_points_discount_session();
            $order->add_order_note(
                __( 'ePappous Club: Εξαργύρωση πόντων δεν εφαρμόστηκε — ο λογαριασμός δεν ανήκει στην ομάδα Pappou Club (B2B King).', 'epappous-club' )
            );
            $order->save();
            return;
        }

        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = CAST(points AS SIGNED) - %d WHERE id = %d AND CAST(points AS SIGNED) >= %d",
                $pts_used,
                $member_id,
                $pts_used
            )
        );
        if ( $updated !== 1 ) {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note(
                __( 'ePappous Club: Η αφαίρεση πόντων απέτυχε, πιθανώς λόγω ανεπαρκούς υπολοίπου κατά την ολοκλήρωση.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => -$pts_used,
                'reason'         => 'checkout_redemption',
                'reference_type' => 'order',
                'reference_id'   => $order->get_id(),
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note(
                __( 'ePappous Club: Αποτυχία καταγραφής εξαργύρωσης πόντων.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        try {
            $order->update_meta_data( '_epc_points_redeemed', $pts_used );
            $order->update_meta_data( '_epc_discount_amount', $discount );
            $order->save();
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note(
                __( 'ePappous Club: Αποτυχία αποθήκευσης μετα-δεδομένων παραγγελίας.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_points_changed', (int) $member_id );
    }

    /**
     * Reset session values used for points redemptions.
     */
    private function clear_points_discount_session() {
        if ( ! WC()->session ) {
            return;
        }
        WC()->session->set( 'epc_points_discount', 0 );
        WC()->session->set( 'epc_points_used', 0 );
        WC()->session->set( 'epc_points_member_id', 0 );
    }

    /**
     * Enqueue checkout JS for points buttons.
     */
    public function enqueue_checkout_js() {
        if ( ! function_exists( 'is_cart' ) ) {
            return;
        }
        if ( ! is_cart() && ! is_checkout() ) {
            return;
        }

        wp_enqueue_script( 'epc-checkout-js', EPC_PLUGIN_URL . 'admin/js/checkout.js', [ 'jquery' ], EPC_VERSION, true );
        $localized = [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'epc_front_nonce' ),
        ];
        if ( function_exists( 'is_checkout' ) && is_checkout() && EPC_Settings::get( 'epc_club_enabled' ) === '1' ) {
            wp_enqueue_style(
                'epc-front-css',
                EPC_PLUGIN_URL . 'admin/css/front.css',
                [],
                EPC_VERSION
            );
            $checkout = \WC_Checkout::instance();
            $localized['checkoutClub'] = [
                'needsCreateAccount' => ! is_user_logged_in()
                    && $checkout->is_registration_enabled(),
            ];
        }
        wp_localize_script( 'epc-checkout-js', 'epcCheckout', $localized );
    }

    /**
     * Register additional checkout fields for the block checkout (WooCommerce 8.9+).
     */
    public function register_block_checkout_club_fields(): void {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        woocommerce_register_additional_checkout_field(
            [
                'id'       => self::BLOCK_FIELD_JOIN,
                'label'    => __( 'Θέλω να εγγραφώ στο Παππού Club', 'epappous-club' ),
                'location' => 'order',
                'type'     => 'checkbox',
                'required' => false,
            ]
        );

        woocommerce_register_additional_checkout_field(
            [
                'id'          => self::BLOCK_FIELD_DOB,
                'label'       => __( 'Ημερομηνία Γέννησης (YYYY-MM-DD)', 'epappous-club' ),
                'location'    => 'order',
                'type'        => 'text',
                'required'    => false,
                'attributes'  => [
                    'placeholder' => 'YYYY-MM-DD',
                ],
                'description' => __( 'Συμπλήρωσε μόνο αν επέλεξες εγγραφή στο Club. Χρειάζεται λογαριασμός πελάτη (όχι αγορά ως επισκέπτης).', 'epappous-club' ),
            ]
        );
    }

    /**
     * Block checkout: validate club fields in the "order" / other group.
     *
     * @param \WP_Error $errors Errors instance.
     * @param array     $fields Additional field values for this location.
     * @param string    $group  billing|shipping|other.
     */
    public function validate_block_checkout_club_fields( \WP_Error $errors, $fields, $group ): void {
        if ( 'other' !== $group || EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        $join_raw = $fields[ self::BLOCK_FIELD_JOIN ] ?? null;
        $join     = ( true === $join_raw || 1 === $join_raw || '1' === $join_raw );
        if ( ! $join ) {
            return;
        }

        $dob = isset( $fields[ self::BLOCK_FIELD_DOB ] ) ? sanitize_text_field( (string) $fields[ self::BLOCK_FIELD_DOB ] ) : '';
        if ( '' === $dob ) {
            $errors->add(
                'epc_club_dob_required',
                __( 'Για εγγραφή στο Παππού Club χρειάζεται η ημερομηνία γέννησής σου.', 'epappous-club' )
            );
            return;
        }

        if ( ! $this->is_valid_dob_string( $dob ) ) {
            $errors->add(
                'epc_club_dob_invalid',
                __( 'Μη έγκυρη ημερομηνία γέννησης. Χρησιμοποίησε τη μορφή ΕΕΕΕ-MM-ΗΗ.', 'epappous-club' )
            );
            return;
        }

        $min_age = (int) EPC_Settings::get( 'epc_min_age' );
        if ( $min_age > 0 ) {
            try {
                $birth = new \DateTime( $dob );
                $now   = new \DateTime();
                $age   = (int) $now->diff( $birth )->y;
                if ( $age < $min_age ) {
                    $errors->add(
                        'epc_club_dob_age',
                        sprintf(
                            /* translators: %d: minimum age */
                            __( 'Πρέπει να είσαι τουλάχιστον %d ετών για εγγραφή στο Club.', 'epappous-club' ),
                            $min_age
                        )
                    );
                }
            } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            }
        }
    }

    /**
     * Classic checkout: club opt-in + DOB below order notes.
     *
     * @param \WC_Checkout $checkout Checkout instance.
     */
    public function render_checkout_club_fields( $checkout ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        if ( is_user_logged_in() ) {
            if ( $this->is_user_epc_member( get_current_user_id() ) ) {
                return;
            }
        } elseif ( ! \WC_Checkout::instance()->is_registration_enabled() ) {
            return;
        }

        $needs_create_account = ! is_user_logged_in() && \WC_Checkout::instance()->is_registration_enabled();
        $wrap_classes         = 'epc-checkout-club-wrap woocommerce-additional-fields__field-wrapper';
        if ( $needs_create_account ) {
            $wrap_classes .= ' epc-checkout-club--needs-account';
        }
        ?>
        <div class="<?php echo esc_attr( $wrap_classes ); ?>">
            <div id="epc-checkout-club-section" class="epc-checkout-club-inner"<?php echo $needs_create_account ? ' style="display:none;"' : ''; ?>>
                <h3><?php esc_html_e( 'Παππού Club', 'epappous-club' ); ?></h3>
                <p class="form-row form-row-wide epc-checkout-club-checkbox">
                    <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                        <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
                               name="epc_checkout_join_club" id="epc_checkout_join_club" value="1" />
                        <span class="woocommerce-form__input-checkbox__label"><?php esc_html_e( 'Θέλω να εγγραφώ στο Παππού Club', 'epappous-club' ); ?></span>
                    </label>
                </p>
                <?php if ( $needs_create_account ) : ?>
                    <p class="form-row form-row-wide epc-checkout-club-hint">
                        <?php esc_html_e( 'Για να εγγραφείς στο Club πρέπει να επιλέξεις «Δημιουργία λογαριασμού» παραπάνω (όχι αγορά ως επισκέπτης).', 'epappous-club' ); ?>
                    </p>
                <?php endif; ?>
                <p class="form-row form-row-wide" id="epc-checkout-club-dob-wrap" style="display:none;">
                    <label for="epc_checkout_dob"><?php esc_html_e( 'Ημερομηνία Γέννησης', 'epappous-club' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'υποχρεωτικό', 'epappous-club' ); ?>">*</abbr></label>
                    <input type="date" class="input-text" name="epc_checkout_dob" id="epc_checkout_dob"
                           max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" autocomplete="bday" />
                    <span class="description"><?php esc_html_e( 'Χρησιμοποιείται για το δώρο πόντων στα γενέθλιά σου.', 'epappous-club' ); ?></span>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Classic checkout: validate club fields.
     */
    public function validate_checkout_club_fields(): void {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $join = ! empty( $_POST['epc_checkout_join_club'] );
        if ( ! $join ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $dob = isset( $_POST['epc_checkout_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['epc_checkout_dob'] ) ) : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $billing_email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

        if ( $this->is_email_epc_member( $billing_email ) ) {
            wc_add_notice(
                __( 'Αυτό το email είναι ήδη εγγεγραμμένο στο Παππού Club.', 'epappous-club' ),
                'error'
            );
            return;
        }

        if ( ! is_user_logged_in() ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $create = ! empty( $_POST['createaccount'] );
            if ( ! $create ) {
                wc_add_notice(
                    __( 'Για εγγραφή στο Παππού Club πρέπει να επιλέξεις δημιουργία λογαριασμού πελάτη (όχι αγορά ως επισκέπτης).', 'epappous-club' ),
                    'error'
                );
                return;
            }
        }

        if ( '' === $dob ) {
            wc_add_notice(
                __( 'Για εγγραφή στο Παππού Club χρειάζεται η ημερομηνία γέννησής σου.', 'epappous-club' ),
                'error'
            );
            return;
        }

        if ( ! $this->is_valid_dob_string( $dob ) ) {
            wc_add_notice(
                __( 'Μη έγκυρη ημερομηνία γέννησης.', 'epappous-club' ),
                'error'
            );
            return;
        }

        $min_age = (int) EPC_Settings::get( 'epc_min_age' );
        if ( $min_age > 0 ) {
            try {
                $birth = new \DateTime( $dob );
                $now   = new \DateTime();
                $age   = (int) $now->diff( $birth )->y;
                if ( $age < $min_age ) {
                    wc_add_notice(
                        sprintf(
                            /* translators: %d: minimum age */
                            __( 'Πρέπει να είσαι τουλάχιστον %d ετών για εγγραφή στο Club.', 'epappous-club' ),
                            $min_age
                        ),
                        'error'
                    );
                }
            } catch ( \Throwable $e ) {
                wc_add_notice(
                    __( 'Μη έγκυρη ημερομηνία γέννησης.', 'epappous-club' ),
                    'error'
                );
            }
        }
    }

    /**
     * Persist classic checkout club fields on the order.
     *
     * @param \WC_Order $order Order.
     * @param array     $data  Checkout posted data.
     */
    public function save_checkout_club_fields_to_order( $order, $data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $_POST['epc_checkout_join_club'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $dob = isset( $_POST['epc_checkout_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['epc_checkout_dob'] ) ) : '';

        $order->update_meta_data( '_epc_checkout_join_club', '1' );
        if ( '' !== $dob ) {
            $order->update_meta_data( '_epc_checkout_dob', $dob );
        }
    }

    /**
     * Block checkout: delegate to shared handler.
     *
     * @param \WC_Order $order Order.
     */
    public function maybe_register_club_member_from_order_blocks( $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $this->maybe_register_club_member_from_order( $order->get_id() );
    }

    /**
     * After checkout: create epc_members row when customer opted in (classic or block checkout).
     *
     * @param int $order_id Order ID.
     */
    public function maybe_register_club_member_from_order( $order_id ): void {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( '_epc_checkout_club_processed', true ) ) {
            return;
        }

        $join = $order->get_meta( '_epc_checkout_join_club', true ) === '1';
        $dob  = (string) $order->get_meta( '_epc_checkout_dob', true );

        if ( ! $join ) {
            $join = $this->get_block_order_meta_other( $order, self::BLOCK_FIELD_JOIN ) === '1';
            if ( '' === $dob ) {
                $dob = $this->get_block_order_meta_other( $order, self::BLOCK_FIELD_DOB );
            }
        }

        if ( ! $join ) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id < 1 ) {
            $order->update_meta_data( '_epc_checkout_club_processed', '1' );
            $order->add_order_note(
                __( 'Παππού Club: ζητήθηκε εγγραφή αλλά δεν υπάρχει λογαριασμός πελάτη στην παραγγελία — η εγγραφή δεν ολοκληρώθηκε.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        if ( $this->is_user_epc_member( $user_id ) ) {
            $order->update_meta_data( '_epc_checkout_club_processed', '1' );
            $order->add_order_note( __( 'Παππού Club: ο πελάτης είναι ήδη μέλος.', 'epappous-club' ) );
            $order->save();
            return;
        }

        $email = sanitize_email( $order->get_billing_email() );
        if ( ! is_email( $email ) ) {
            $order->update_meta_data( '_epc_checkout_club_processed', '1' );
            $order->add_order_note( __( 'Παππού Club: μη έγκυρο email χρέωσης — εγγραφή ακυρώθηκε.', 'epappous-club' ) );
            $order->save();
            return;
        }

        if ( $this->is_email_epc_member( $email ) ) {
            $order->update_meta_data( '_epc_checkout_club_processed', '1' );
            $order->add_order_note( __( 'Παππού Club: το email είναι ήδη μέλος.', 'epappous-club' ) );
            $order->save();
            return;
        }

        if ( '' === $dob || ! $this->is_valid_dob_string( $dob ) ) {
            $order->update_meta_data( '_epc_checkout_club_processed', '1' );
            $order->add_order_note(
                __( 'Παππού Club: λείπει ή είναι άκυρη η ημερομηνία γέννησης — η εγγραφή δεν ολοκληρώθηκε.', 'epappous-club' )
            );
            $order->save();
            return;
        }

        $min_age = (int) EPC_Settings::get( 'epc_min_age' );
        if ( $min_age > 0 ) {
            try {
                $birth = new \DateTime( $dob );
                $now   = new \DateTime();
                $age   = (int) $now->diff( $birth )->y;
                if ( $age < $min_age ) {
                    $order->update_meta_data( '_epc_checkout_club_processed', '1' );
                    $order->add_order_note(
                        sprintf(
                            /* translators: %d: minimum age */
                            __( 'Παππού Club: ηλικία κάτω του επιτρεπτού ορίου (%d) — εγγραφή ακυρώθηκε.', 'epappous-club' ),
                            $min_age
                        )
                    );
                    $order->save();
                    return;
                }
            } catch ( \Throwable $e ) {
                $order->update_meta_data( '_epc_checkout_club_processed', '1' );
                $order->add_order_note( __( 'Παππού Club: άκυρη ημερομηνία γέννησης — εγγραφή ακυρώθηκε.', 'epappous-club' ) );
                $order->save();
                return;
            }
        }

        global $wpdb;
        $referral_code = EPC_Referral::generate_code();
        $first           = sanitize_text_field( $order->get_billing_first_name() );
        $last            = sanitize_text_field( $order->get_billing_last_name() );
        $phone           = sanitize_text_field( $order->get_billing_phone() );
        $wp_user         = get_userdata( $user_id );

        if ( '' === $first && $wp_user ) {
            $first = sanitize_text_field( $wp_user->first_name ?: $wp_user->display_name );
        }
        if ( '' === $last && $wp_user ) {
            $last = sanitize_text_field( $wp_user->last_name );
        }
        if ( '' === $first ) {
            $first = __( 'Πελάτης', 'epappous-club' );
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_members",
            [
                'user_id'       => $user_id,
                'first_name'    => $first,
                'last_name'     => $last,
                'email'         => $email,
                'phone'         => $phone,
                'date_of_birth' => $dob,
                'referral_code' => $referral_code,
                'points'        => 0,
                'tier'          => 'basic',
                'status'        => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            $order->update_meta_data( '_epc_checkout_club_processed', '1' );
            $order->add_order_note( __( 'Παππού Club: αποτυχία αποθήκευσης μέλους στη βάση.', 'epappous-club' ) );
            $order->save();
            return;
        }

        $member_id = (int) $wpdb->insert_id;

        EPC_Member_Sync::after_club_registration( $member_id, $email );

        do_action(
            'epc_member_registered',
            $member_id,
            [
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
                'source'     => 'checkout',
            ]
        );

        $order->update_meta_data( '_epc_checkout_club_processed', '1' );
        $order->add_order_note(
            sprintf(
                /* translators: %s: referral code */
                __( 'Παππού Club: νέα εγγραφή μέλους από checkout. Referral: %s', 'epappous-club' ),
                $referral_code
            )
        );
        $order->save();
    }

    /**
     * Admin: show checkout club meta when present.
     *
     * @param \WC_Order|false $order Order.
     */
    public function render_checkout_club_order_meta( $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $join = $order->get_meta( '_epc_checkout_join_club', true ) === '1';
        $dob  = (string) $order->get_meta( '_epc_checkout_dob', true );
        if ( ! $join ) {
            $join = $this->get_block_order_meta_other( $order, self::BLOCK_FIELD_JOIN ) === '1';
            if ( '' === $dob ) {
                $dob = $this->get_block_order_meta_other( $order, self::BLOCK_FIELD_DOB );
            }
        }
        if ( ! $join && '' === $dob ) {
            return;
        }
        echo '<div class="address" style="clear:both;margin-top:10px;">';
        echo '<p><strong>' . esc_html__( 'Παππού Club (checkout)', 'epappous-club' ) . '</strong></p>';
        echo '<p>' . esc_html__( 'Εγγραφή στο Club:', 'epappous-club' ) . ' ' . ( $join ? esc_html__( 'Ναι', 'epappous-club' ) : esc_html__( 'Όχι', 'epappous-club' ) ) . '</p>';
        if ( '' !== $dob ) {
            echo '<p>' . esc_html__( 'Ημερομηνία γέννησης:', 'epappous-club' ) . ' ' . esc_html( $dob ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * @param int $user_id WordPress user ID.
     */
    private function is_user_epc_member( int $user_id ): bool {
        if ( $user_id < 1 ) {
            return false;
        }
        global $wpdb;
        $c = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE user_id = %d AND status = 'active'",
                $user_id
            )
        );
        return $c > 0;
    }

    private function is_email_epc_member( string $email ): bool {
        if ( ! is_email( $email ) ) {
            return false;
        }
        global $wpdb;
        $c = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active'",
                $email
            )
        );
        return $c > 0;
    }

    private function is_valid_dob_string( string $dob ): bool {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return false;
        }
        $parts = array_map( 'intval', explode( '-', $dob ) );
        return wp_checkdate( $parts[1], $parts[2], $parts[0], $dob );
    }

    /**
     * Read block additional field from order meta (_wc_other/...).
     */
    private function get_block_order_meta_other( \WC_Order $order, string $field_id ): string {
        $key = '_wc_other/' . $field_id;
        return (string) $order->get_meta( $key, true );
    }

    /*
    private function get_tier_multiplier( string $tier ): float {
        $tiers_json = EPC_Settings::get( 'epc_tiers' );
        $tiers = json_decode( $tiers_json, true );
        if ( ! is_array( $tiers ) ) {
            return 1.0;
        }
        foreach ( $tiers as $t ) {
            if ( ( $t['slug'] ?? '' ) === $tier ) {
                return (float) ( $t['multiplier'] ?? 1.0 );
            }
        }
        return 1.0;
    }
    */
}
