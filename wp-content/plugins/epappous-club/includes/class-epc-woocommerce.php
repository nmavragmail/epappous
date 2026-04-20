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

        // Backup hooks so points are never missed (payment received, thank-you, any status change to eligible).
        add_action( 'woocommerce_payment_complete', [ $this, 'earn_points_on_order' ], 25 );
        add_action( 'woocommerce_thankyou', [ $this, 'earn_points_on_order' ], 25 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed_backup' ], 25, 4 );

        // Catch up legacy / imported orders when admin opens them.
        add_action( 'admin_init', [ $this, 'maybe_catch_up_order_on_admin_view' ] );

        // Persist a "would-be earned points" meta on every order, regardless of status,
        // so admin metabox + transactional emails can always show it.
        add_action( 'woocommerce_new_order', [ $this, 'maybe_persist_potential_points' ], 30 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'maybe_persist_potential_points' ], 30 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'maybe_persist_potential_points_blocks' ], 30 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'refresh_potential_points_on_status' ], 30, 4 );

        // Redeem points at checkout
        add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'render_redeem_ui' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_redeem_ui' ] );

        // Referral link box at cart/checkout
        add_action( 'woocommerce_before_cart_totals', [ $this, 'render_referral_cart_box' ] );
        add_action( 'woocommerce_review_order_after_submit', [ $this, 'render_referral_cart_box' ] );
        add_action( 'wp_ajax_epc_apply_points_discount', [ $this, 'ajax_apply_discount' ] );
        add_action( 'wp_ajax_epc_remove_points_discount', [ $this, 'ajax_remove_discount' ] );
        add_action( 'wp_ajax_epc_send_cassette_gift_email', [ $this, 'ajax_send_cassette_gift_email' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_points_fee' ] );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'record_points_redemption' ], 20 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'record_points_redemption_from_blocks' ], 20, 2 );
        if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.2.0', '<' ) ) {
            add_action( '__experimental_woocommerce_blocks_checkout_update_order_from_request', [ $this, 'record_points_redemption_from_blocks' ], 20, 2 );
        }
        // Blocks: also settle redemption after the order exists (session/cart may differ).
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'record_points_redemption_blocks_after_order' ], 25, 1 );

        // Old inline admin order panels removed; using sidebar metaboxes instead.
        add_action( 'add_meta_boxes', [ $this, 'register_order_side_metaboxes' ], 35 );

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

        // Admin order action: manually recalculate club points for this order.
        add_filter( 'woocommerce_order_actions', [ $this, 'register_order_recalculate_action' ] );
        add_action( 'woocommerce_order_action_epc_recalculate_points', [ $this, 'handle_order_recalculate_action' ] );
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
     * Register order side metaboxes (shown in admin sidebar).
     */
    public function register_order_side_metaboxes(): void {
        if ( ! function_exists( 'add_meta_box' ) ) {
            return;
        }

        $screens = [ 'shop_order' ];
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $hpos_screen = wc_get_page_screen_id( 'shop-order' );
            if ( is_string( $hpos_screen ) && '' !== $hpos_screen ) {
                $screens[] = $hpos_screen;
            }
        }

        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box(
                'epc-order-gift-box',
                __( 'Κασσετίνα - Δώρο', 'epappous-club' ),
                [ $this, 'render_order_gift_status_metabox' ],
                $screen,
                'side',
                'low'
            );
            add_meta_box(
                'epc-order-points-box',
                __( 'Pappou Club — Πόντοι Παραγγελίας', 'epappous-club' ),
                [ $this, 'render_order_points_summary_metabox' ],
                $screen,
                'side',
                'low'
            );
            add_meta_box(
                'epc-order-debug-box',
                __( 'Pappou Club — Διαγνωστικά πόντων', 'epappous-club' ),
                [ $this, 'render_order_points_debug_metabox' ],
                $screen,
                'side',
                'low'
            );
        }
    }

    /**
     * Add manual recalculate action in order actions dropdown.
     *
     * @param array<string,string> $actions Existing order actions.
     * @return array<string,string>
     */
    public function register_order_recalculate_action( array $actions ): array {
        $actions['epc_recalculate_points'] = __( 'Recalculate Pappou Club points', 'epappous-club' );
        return $actions;
    }

    /**
     * Run a manual recalculation for the order's earned points.
     *
     * @param \WC_Order $order Order object from WooCommerce action.
     */
    public function handle_order_recalculate_action( $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $result = $this->recalculate_earned_points_for_order( $order );
        $order->add_order_note( $result );
        $order->save();
    }

    /**
     * Backup hook: any status change into an eligible status (processing/completed) for an order
     * that has not been settled yet triggers earning. Helps when the original transition happened
     * before the dedicated status hook was registered.
     *
     * @param int    $order_id Order ID.
     * @param string $from     From status.
     * @param string $to       To status.
     * @param mixed  $order    Order object (may be WC_Order).
     */
    public function on_order_status_changed_backup( $order_id, $from, $to, $order ): void {
        unset( $from );
        $order_id = (int) $order_id;
        if ( $order_id < 1 ) {
            return;
        }
        if ( ! in_array( (string) $to, [ 'processing', 'completed' ], true ) ) {
            return;
        }
        if ( $order instanceof \WC_Order && $order->get_meta( '_epc_club_loyalty_settled', true ) === '1' ) {
            return;
        }
        $this->earn_points_on_order( $order_id );
    }

    /**
     * Catch up legacy / imported orders the moment an admin opens them.
     *
     * If an order is in an eligible status but was never processed for points (e.g. transition
     * happened before this hook existed, or under a previous plugin version), run earning now.
     */
    public function maybe_catch_up_order_on_admin_view(): void {
        // Cheap short-circuits BEFORE any DB / option lookups so this hook
        // costs almost nothing on the 99% of admin requests that aren't an
        // order-edit screen.
        if ( ! is_admin() ) {
            return;
        }

        $is_legacy_order_edit = isset( $_GET['post'], $_GET['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'edit' === $_GET['action']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_hpos_order_edit   = isset( $_GET['page'], $_GET['action'], $_GET['id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'wc-orders' === $_GET['page'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'edit' === $_GET['action']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $is_legacy_order_edit && ! $is_hpos_order_edit ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        $order_id = 0;

        if ( $is_legacy_order_edit ) {
            $maybe = (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( $maybe > 0 && get_post_type( $maybe ) === 'shop_order' ) {
                $order_id = $maybe;
            }
        }

        if ( $order_id < 1 && $is_hpos_order_edit ) {
            $order_id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( $order_id < 1 ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        if ( $order->get_meta( '_epc_club_loyalty_settled', true ) === '1' ) {
            return;
        }
        if ( ! in_array( (string) $order->get_status(), [ 'processing', 'completed' ], true ) ) {
            return;
        }

        $this->earn_points_on_order( $order_id );
    }

    /**
     * Sidebar metabox: gift received state and date for this order's user.
     *
     * @param mixed $post_or_order WP_Post|WC_Order depending storage mode.
     */
    public function render_order_gift_status_metabox( $post_or_order ): void {
        $order = $this->resolve_order_from_admin_context( $post_or_order );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Δεν βρέθηκε παραγγελία.', 'epappous-club' ) . '</p>';
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id < 1 ) {
            $email = sanitize_email( (string) $order->get_billing_email() );
            if ( is_email( $email ) ) {
                $u = get_user_by( 'email', $email );
                if ( $u ) {
                    $user_id = (int) $u->ID;
                }
            }
        }

        if ( $user_id < 1 ) {
            echo '<p>' . esc_html__( 'Δεν υπάρχει συνδεδεμένος χρήστης.', 'epappous-club' ) . '</p>';
            return;
        }

        $received = get_user_meta( $user_id, 'epc_cassette_gift_received', true ) === 'yes';
        $raw_date = (string) get_user_meta( $user_id, 'epc_cassette_gift_date', true );
        $date_txt = '—';
        if ( '' !== $raw_date ) {
            $ts = strtotime( $raw_date . ' 12:00:00' );
            $date_txt = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : $raw_date;
        }

        echo '<p><strong>' . esc_html__( 'Έχει πάρει Κασσετίνα - Δώρο:', 'epappous-club' ) . '</strong> ' .
            esc_html( $received ? __( 'Ναι', 'epappous-club' ) : __( 'Όχι', 'epappous-club' ) ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Ημερομηνία δώρου:', 'epappous-club' ) . '</strong> ' . esc_html( $date_txt ) . '</p>';
        echo '<p style="margin-top:10px;">';
        echo '<button type="button" class="button button-primary epc-send-cassette-email-btn" data-order-id="' . (int) $order->get_id() . '" data-user-id="' . (int) $user_id . '" data-nonce="' . esc_attr( wp_create_nonce( 'epc_admin_nonce' ) ) . '">' . esc_html__( 'Ενημέρωση πελάτη για κασσετίνα', 'epappous-club' ) . '</button>';
        echo '</p>';
        echo '<p class="epc-cassette-order-email-msg" style="display:none;margin-top:8px;"></p>';
    }

    /**
     * AJAX: Send cassette gift update email from order metabox and mark user as received gift.
     */
    public function ajax_send_cassette_gift_email(): void {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $user_id  = (int) ( $_POST['user_id'] ?? 0 );
        if ( $order_id < 1 || $user_id < 1 ) {
            wp_send_json_error( __( 'Λείπουν δεδομένα παραγγελίας/χρήστη.', 'epappous-club' ) );
        }
        if ( ! EPC_Capabilities::current_user_can_edit_wp_user( $user_id ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            wp_send_json_error( __( 'Δεν βρέθηκε παραγγελία.', 'epappous-club' ) );
        }

        $to_email = sanitize_email( (string) $order->get_billing_email() );
        if ( ! is_email( $to_email ) ) {
            $wp_user = get_userdata( $user_id );
            if ( $wp_user && is_email( $wp_user->user_email ) ) {
                $to_email = sanitize_email( $wp_user->user_email );
            }
        }
        if ( ! is_email( $to_email ) ) {
            wp_send_json_error( __( 'Δεν βρέθηκε έγκυρο email πελάτη.', 'epappous-club' ) );
        }

        $subject = __( 'Ενημέρωση για κασσετίνα δώρο', 'epappous-club' );
        $body    = (string) EPC_Settings::get( 'epc_cassette_gift_email_body' );
        if ( trim( wp_strip_all_tags( $body ) ) === '' ) {
            $body = sprintf(
                "%s\n\n%s",
                __( 'Η ενημέρωση για την κασσετίνα δώρο είναι έτοιμη.', 'epappous-club' ),
                home_url( '/' )
            );
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $sent    = wp_mail( $to_email, $subject, wpautop( $body ), $headers );
        if ( ! $sent ) {
            wp_send_json_error( __( 'Η αποστολή email απέτυχε.', 'epappous-club' ) );
        }

        update_user_meta( $user_id, EPC_User_Profile::USER_META_CASSETTE, 'yes' );
        update_user_meta( $user_id, EPC_User_Profile::USER_META_CASSETTE_DATE, gmdate( 'Y-m-d', current_time( 'timestamp' ) ) );
        update_user_meta( $user_id, EPC_User_Profile::USER_META_CASSETTE_EDITED_BY, get_current_user_id() );
        update_user_meta( $user_id, EPC_User_Profile::USER_META_CASSETTE_EDITED_AT, current_time( 'mysql' ) );

        $order->add_order_note(
            sprintf(
                /* translators: %s: recipient email */
                __( 'Pappou Club: στάλθηκε email «Ενημέρωση πελάτη για κασσετίνα» στο %s και ενημερώθηκε το πεδίο δώρου.', 'epappous-club' ),
                $to_email
            )
        );
        $order->save();

        wp_send_json_success(
            [
                'message'   => __( 'Το email στάλθηκε και το πεδίο δώρου ενημερώθηκε σε ΝΑΙ.', 'epappous-club' ),
                'date_text' => date_i18n( get_option( 'date_format' ) ),
            ]
        );
    }

    /**
     * Sidebar metabox: points earned/redeemed and gift products in this order.
     *
     * @param mixed $post_or_order WP_Post|WC_Order depending storage mode.
     */
    public function render_order_points_summary_metabox( $post_or_order ): void {
        $order = $this->resolve_order_from_admin_context( $post_or_order );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Δεν βρέθηκε παραγγελία.', 'epappous-club' ) . '</p>';
            return;
        }

        $earned_meta = $order->get_meta( '_epc_points_earned', true );
        $earned      = '' !== (string) $earned_meta ? (int) $earned_meta : null;
        $settled     = $order->get_meta( '_epc_club_loyalty_settled', true ) === '1';
        $revoked     = $order->get_meta( '_epc_points_revoked', true ) === '1';
        $potential   = $this->ensure_potential_points_meta( $order );
        $redeem      = (int) $order->get_meta( '_epc_points_redeemed', true );

        $gift_lines = [];
        if ( class_exists( 'EPC_Gift_Products' ) ) {
            foreach ( $order->get_items() as $item ) {
                $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
                if ( $product && EPC_Gift_Products::is_gift_product( $product ) ) {
                    $pts          = (int) $item->get_meta( '_epc_gift_points', true );
                    $gift_lines[] = [
                        'name'   => $product->get_name(),
                        'qty'    => (int) $item->get_quantity(),
                        'points' => $pts > 0 ? $pts : (int) ( EPC_Gift_Products::points_cost( $product ) * (int) $item->get_quantity() ),
                    ];
                }
            }
        }

        if ( $revoked ) {
            $revoked_amount = (int) $order->get_meta( '_epc_points_revoked_amount', true );
            echo '<p><strong>' . esc_html__( 'Πόντοι παραγγελίας:', 'epappous-club' ) . '</strong> ' .
                esc_html(
                    $revoked_amount > 0
                        ? sprintf( __( 'Ακυρώθηκαν (%d πόντοι)', 'epappous-club' ), $revoked_amount )
                        : __( 'Ακυρώθηκαν', 'epappous-club' )
                ) . '</p>';
        } elseif ( $settled && null !== $earned ) {
            echo '<p><strong>' . esc_html__( 'Πόντοι που κέρδισε:', 'epappous-club' ) . '</strong> ' . esc_html( (string) $earned ) . '</p>';
        } else {
            echo '<p><strong>' . esc_html__( 'Πόντοι (εκκρεμείς):', 'epappous-club' ) . '</strong> ' . esc_html( (string) $potential ) . '</p>';
            echo '<p style="color:#666;font-size:11px;margin-top:-6px;">' .
                esc_html__( 'Θα προστεθούν στον χρήστη όταν η παραγγελία περάσει σε επιλέξιμο status (processing/completed).', 'epappous-club' ) . '</p>';
        }
        echo '<p><strong>' . esc_html__( 'Πόντοι που εξαργύρωσε (slider checkout):', 'epappous-club' ) . '</strong> ' . esc_html( (string) $redeem ) . '</p>';

        if ( ! empty( $gift_lines ) ) {
            $gift_total = 0;
            $rows       = [];
            foreach ( $gift_lines as $g ) {
                $gift_total += (int) $g['points'];
                $rows[]      = sprintf( '%s × %d (%d %s)', $g['name'], $g['qty'], (int) $g['points'], __( 'πόντοι', 'epappous-club' ) );
            }
            echo '<p><strong>' . esc_html__( 'Δώρα με πόντους σε αυτή την παραγγελία:', 'epappous-club' ) . '</strong><br>' .
                esc_html( implode( ', ', $rows ) ) . '<br>' .
                '<em>' . sprintf( esc_html__( 'Σύνολο πόντων που αφαιρέθηκαν για δώρα: %d', 'epappous-club' ), $gift_total ) . '</em></p>';
        } else {
            echo '<p><strong>' . esc_html__( 'Δώρα με πόντους σε αυτή την παραγγελία:', 'epappous-club' ) . '</strong> ' . esc_html__( 'Όχι', 'epappous-club' ) . '</p>';
        }
    }

    /**
     * Sidebar metabox: step-by-step diagnosis of why the order earned (or didn't earn) club points.
     *
     * @param mixed $post_or_order WP_Post|WC_Order depending on storage mode.
     */
    public function render_order_points_debug_metabox( $post_or_order ): void {
        $order = $this->resolve_order_from_admin_context( $post_or_order );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Δεν βρέθηκε παραγγελία.', 'epappous-club' ) . '</p>';
            return;
        }

        $diag = $this->diagnose_order_points( $order );

        $verdict_color = $diag['verdict_pass'] ? '#1a7f37' : '#9a1a1a';
        echo '<div style="padding:8px;border-left:3px solid ' . esc_attr( $verdict_color ) .
            ';background:#f6f7f7;margin-bottom:8px;font-size:12px;line-height:1.5;">';
        echo '<strong>' . esc_html__( 'Συμπέρασμα:', 'epappous-club' ) . '</strong> ' . esc_html( $diag['verdict'] );
        echo '</div>';

        echo '<details style="margin-bottom:8px;"><summary style="cursor:pointer;font-weight:600;">' .
            esc_html__( 'Έλεγχοι (κάνε κλικ για ανάπτυξη)', 'epappous-club' ) . '</summary>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:11.5px;margin-top:6px;">';
        foreach ( $diag['checks'] as $check ) {
            $icon  = $check['pass'] ? '✓' : '✗';
            $color = $check['pass'] ? '#1a7f37' : '#9a1a1a';
            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:4px 6px 4px 0;vertical-align:top;color:' . esc_attr( $color ) . ';font-weight:700;width:18px;">' . esc_html( $icon ) . '</td>';
            echo '<td style="padding:4px 0;vertical-align:top;">';
            echo '<strong>' . esc_html( $check['label'] ) . '</strong>';
            if ( ! empty( $check['value'] ) ) {
                echo '<br><span style="color:#555;">' . esc_html( $check['value'] ) . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</details>';

        if ( ! empty( $diag['items_breakdown'] ) ) {
            echo '<details><summary style="cursor:pointer;font-weight:600;">' .
                esc_html__( 'Ανάλυση προϊόντων', 'epappous-club' ) . '</summary>';
            echo '<table style="width:100%;border-collapse:collapse;font-size:11.5px;margin-top:6px;table-layout:fixed;word-break:break-word;">';
            echo '<thead><tr style="border-bottom:1px solid #ccc;text-align:left;">';
            echo '<th style="padding:3px 4px;width:55%;">' . esc_html__( 'Προϊόν', 'epappous-club' ) . '</th>';
            echo '<th style="padding:3px 4px;width:25%;text-align:right;white-space:nowrap;">' . esc_html__( 'Σύνολο', 'epappous-club' ) . '</th>';
            echo '<th style="padding:3px 4px;width:20%;">' . esc_html__( 'Status', 'epappous-club' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $diag['items_breakdown'] as $line ) {
                $color = $line['eligible'] ? '#1a7f37' : '#9a1a1a';
                $label = $line['eligible'] ? __( 'Επιλέξιμο', 'epappous-club' ) : ( $line['reason'] ?? __( 'Μη επιλέξιμο', 'epappous-club' ) );
                echo '<tr style="border-bottom:1px solid #f0f0f0;">';
                echo '<td style="padding:3px 4px;">' . esc_html( $line['name'] ) . '</td>';
                echo '<td style="padding:3px 4px;text-align:right;white-space:nowrap;">' . wp_kses_post( wc_price( $line['total'] ) ) . '</td>';
                echo '<td style="padding:3px 4px;color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody><tfoot>';

            // Brutto products subtotal
            echo '<tr style="border-top:1px solid #ccc;font-weight:600;">';
            echo '<td style="padding:4px;">' . esc_html__( 'Σύνολο επιλέξιμων προϊόντων', 'epappous-club' ) . '</td>';
            echo '<td style="padding:4px;text-align:right;white-space:nowrap;" colspan="2">' . wp_kses_post( wc_price( $diag['items_gross'] ) ) . '</td>';
            echo '</tr>';

            // Shipping line — show whether it counts toward earning
            if ( $diag['shipping_total'] > 0 ) {
                $ship_color = $diag['shipping_counts'] ? '#1a7f37' : '#9a1a1a';
                $ship_label = $diag['shipping_counts']
                    ? __( '+ Μεταφορικά (μετράνε)', 'epappous-club' )
                    : __( '+ Μεταφορικά (δεν μετράνε)', 'epappous-club' );
                echo '<tr><td style="padding:4px;color:' . esc_attr( $ship_color ) . ';">' . esc_html( $ship_label ) . '</td>';
                echo '<td style="padding:4px;text-align:right;white-space:nowrap;color:' . esc_attr( $ship_color ) . ';" colspan="2">' . wp_kses_post( wc_price( $diag['shipping_total'] ) ) . '</td>';
                echo '</tr>';
            }

            // Points-redemption discount
            if ( $diag['points_discount'] > 0 ) {
                echo '<tr><td style="padding:4px;color:#9a1a1a;">' . esc_html__( '− Έκπτωση από εξαργύρωση πόντων', 'epappous-club' ) . '</td>';
                echo '<td style="padding:4px;text-align:right;white-space:nowrap;color:#9a1a1a;" colspan="2">−' . wp_kses_post( wc_price( $diag['points_discount'] ) ) . '</td>';
                echo '</tr>';
            }

            // Net eligible base used for the actual award
            echo '<tr><td style="padding:4px;font-weight:700;">' . esc_html__( 'Καθαρή επιλέξιμη βάση', 'epappous-club' ) . '</td>';
            echo '<td style="padding:4px;text-align:right;font-weight:700;white-space:nowrap;" colspan="2">' . wp_kses_post( wc_price( $diag['eligible_total'] ) ) . '</td>';
            echo '</tr>';

            echo '<tr><td style="padding:4px;">' . esc_html__( 'Πόντοι/€', 'epappous-club' ) . '</td>';
            echo '<td style="padding:4px;text-align:right;white-space:nowrap;" colspan="2">' . esc_html( (string) $diag['points_per_euro'] ) . '</td>';
            echo '</tr>';

            // Show gross-vs-net breakdown when redemption was used,
            // otherwise just show the single calculated number.
            if ( $diag['points_discount'] > 0 && $diag['potential_gross'] !== $diag['potential'] ) {
                echo '<tr><td style="padding:4px;color:#555;">' . esc_html__( 'Πόντοι χωρίς εξαργύρωση', 'epappous-club' ) . '</td>';
                echo '<td style="padding:4px;text-align:right;white-space:nowrap;color:#555;text-decoration:line-through;" colspan="2">' . esc_html( (string) $diag['potential_gross'] ) . '</td>';
                echo '</tr>';
                echo '<tr><td style="padding:4px;font-weight:700;">' . esc_html__( 'Πόντοι που πρέπει να αποδοθούν', 'epappous-club' ) . '</td>';
                echo '<td style="padding:4px;text-align:right;font-weight:700;white-space:nowrap;" colspan="2">' . esc_html( (string) $diag['potential'] ) . '</td>';
                echo '</tr>';
            } else {
                echo '<tr><td style="padding:4px;font-weight:700;">' . esc_html__( 'Υπολογισμένοι πόντοι', 'epappous-club' ) . '</td>';
                echo '<td style="padding:4px;text-align:right;font-weight:700;white-space:nowrap;" colspan="2">' . esc_html( (string) $diag['potential'] ) . '</td>';
                echo '</tr>';
            }

            // Actually awarded points (from order meta)
            if ( null !== $diag['earned'] ) {
                $awarded_color = ( (int) $diag['earned'] === (int) $diag['potential'] ) ? '#1a7f37' : '#b07d00';
                echo '<tr style="border-top:1px solid #ccc;"><td style="padding:6px 4px;font-weight:700;">' . esc_html__( 'Πραγματικά αποδοθέντες πόντοι', 'epappous-club' ) . '</td>';
                echo '<td style="padding:6px 4px;text-align:right;font-weight:700;white-space:nowrap;color:' . esc_attr( $awarded_color ) . ';" colspan="2">' . esc_html( (string) (int) $diag['earned'] ) . '</td>';
                echo '</tr>';
            }

            echo '</tfoot></table>';
            echo '</details>';
        }
    }

    /**
     * Build a structured diagnosis of why the given order earned (or did not earn) club points.
     *
     * @return array{checks:array<int,array{label:string,pass:bool,value:string}>,
     *               items_breakdown:array<int,array{name:string,total:float,on_sale:bool,eligible:bool,reason?:string}>,
     *               eligible_total:float, points_per_euro:float, potential:int,
     *               earned:?int, settled:bool, revoked:bool,
     *               verdict:string, verdict_pass:bool}
     */
    public function diagnose_order_points( \WC_Order $order ): array {
        $checks = [];

        $club_enabled = EPC_Settings::get( 'epc_club_enabled' ) === '1';
        $checks[] = [
            'label' => __( 'Pappou Club ενεργό', 'epappous-club' ),
            'pass'  => $club_enabled,
            'value' => $club_enabled ? __( 'Ναι', 'epappous-club' ) : __( 'Όχι (epc_club_enabled ≠ 1)', 'epappous-club' ),
        ];

        $earn_enabled = EPC_Settings::get( 'epc_woo_earn_on_complete' ) === '1';
        $checks[] = [
            'label' => __( 'Απονομή πόντων από WooCommerce ενεργή', 'epappous-club' ),
            'pass'  => $earn_enabled,
            'value' => $earn_enabled ? __( 'Ναι', 'epappous-club' ) : __( 'Όχι (epc_woo_earn_on_complete ≠ 1)', 'epappous-club' ),
        ];

        $allowed_statuses = json_decode( (string) EPC_Settings::get( 'epc_woo_earn_statuses' ), true );
        if ( ! is_array( $allowed_statuses ) || empty( $allowed_statuses ) ) {
            $allowed_statuses = [ 'completed' ];
        }
        $allowed_statuses = array_values(
            array_intersect( array_map( 'sanitize_key', $allowed_statuses ), [ 'processing', 'completed' ] )
        );
        if ( empty( $allowed_statuses ) ) {
            $allowed_statuses = [ 'completed' ];
        }
        $current_status = (string) $order->get_status();
        $status_ok      = in_array( $current_status, $allowed_statuses, true );
        $checks[] = [
            'label' => __( 'Status παραγγελίας επιλέξιμο για απονομή', 'epappous-club' ),
            'pass'  => $status_ok,
            'value' => sprintf(
                /* translators: 1: current status, 2: list of eligible statuses */
                __( 'Τρέχον: %1$s | Επιλέξιμα: %2$s', 'epappous-club' ),
                $current_status,
                implode( ', ', $allowed_statuses )
            ),
        ];

        $email   = (string) $order->get_billing_email();
        $in_club = EPC_B2BKing::order_customer_in_pappou_club( $order );
        $checks[] = [
            'label' => __( 'Πελάτης σε B2B King Pappou Club group', 'epappous-club' ),
            'pass'  => (bool) $in_club,
            'value' => sprintf( '%s (%s)', $in_club ? __( 'Ναι', 'epappous-club' ) : __( 'Όχι', 'epappous-club' ), $email !== '' ? $email : '—' ),
        ];

        global $wpdb;
        $member = null;
        if ( is_email( $email ) ) {
            $member = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status FROM {$wpdb->prefix}epc_members WHERE email = %s LIMIT 1",
                    $email
                ),
                ARRAY_A
            );
        }
        $member_active = $member && ( ( $member['status'] ?? '' ) === 'active' );
        $checks[] = [
            'label' => __( 'Member βρέθηκε στη βάση & active', 'epappous-club' ),
            'pass'  => (bool) $member_active,
            'value' => $member
                ? sprintf( 'ID #%d, status: %s', (int) $member['id'], $member['status'] )
                : __( 'Δεν βρέθηκε καμία γραμμή στον epc_members', 'epappous-club' ),
        ];

        $settled = $order->get_meta( '_epc_club_loyalty_settled', true ) === '1';
        $revoked = $order->get_meta( '_epc_points_revoked', true ) === '1';
        $checks[] = [
            'label' => __( 'Loyalty settled (έχει ήδη υπολογιστεί)', 'epappous-club' ),
            'pass'  => $settled,
            'value' => $settled
                ? __( 'Ναι, _epc_club_loyalty_settled = 1', 'epappous-club' )
                : __( 'Όχι, η earn_points_on_order δεν έχει τρέξει επιτυχώς για αυτή την παραγγελία', 'epappous-club' ),
        ];

        $points_per_euro   = (float) EPC_Settings::get( 'epc_points_per_euro' );
        $exclude_sale      = EPC_Settings::get( 'epc_woo_exclude_sale_items' ) === '1';
        $exclude_cats_json = EPC_Settings::get( 'epc_woo_exclude_categories' );
        $exclude_cats      = json_decode( (string) $exclude_cats_json, true ) ?: [];
        $include_shipping  = EPC_Settings::get( 'epc_woo_earn_include_shipping' ) === '1';

        $items_breakdown    = [];
        $items_gross_total  = 0.0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $line    = [
                'name'     => $item->get_name(),
                'total'    => (float) $item->get_total(),
                'on_sale'  => $product ? (bool) $product->is_on_sale() : false,
                'eligible' => false,
            ];
            $is_gift = $product && class_exists( 'EPC_Gift_Products' )
                && EPC_Gift_Products::is_gift_product( $product );
            if ( ! $product ) {
                $line['reason'] = __( 'Δεν βρέθηκε προϊόν', 'epappous-club' );
            } elseif ( $is_gift ) {
                $line['reason'] = __( 'Δώρο με πόντους → δεν κερδίζει πόντους', 'epappous-club' );
            } elseif ( $exclude_sale && $product->is_on_sale() ) {
                $line['reason'] = __( 'Σε προσφορά → εξαιρείται', 'epappous-club' );
            } else {
                $excluded_by_cat = false;
                if ( ! empty( $exclude_cats ) ) {
                    $lookup_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
                    $cats      = wp_get_post_terms( $lookup_id, 'product_cat', [ 'fields' => 'ids' ] );
                    if ( array_intersect( (array) $cats, $exclude_cats ) ) {
                        $excluded_by_cat   = true;
                        $line['reason']    = __( 'Εξαιρείται λόγω κατηγορίας', 'epappous-club' );
                    }
                }
                if ( ! $excluded_by_cat ) {
                    $line['eligible']    = true;
                    $items_gross_total  += (float) $item->get_total();
                }
            }
            $items_breakdown[] = $line;
        }

        $shipping_total  = (float) $order->get_shipping_total();
        $points_discount = (float) $order->get_meta( '_epc_discount_amount', true );

        // Net eligible total — same source of truth used by earn_points_on_order
        // and calculate_potential_points_for_order.
        $eligible_total = $this->compute_eligible_order_total( $order );

        // If the order is gift-only (no paid item contributed to the gross
        // total), shipping is intentionally excluded from earning even when
        // the "Συμπεριλαμβάνει μεταφορικά" toggle is on — gift-only orders
        // are settled in points, not euros. Mirror that here so the debug
        // breakdown matches reality instead of showing a phantom gross.
        $gift_only_order   = $items_gross_total <= 0
            && class_exists( 'EPC_Gift_Products' )
            && EPC_Gift_Products::order_gift_points_total( $order ) > 0;
        $shipping_in_gross = $include_shipping && ! $gift_only_order;

        // Gross potential = before subtracting the points-redemption discount.
        // This is what the customer would have earned if they hadn't redeemed.
        $gross_base      = $items_gross_total + ( $shipping_in_gross ? $shipping_total : 0.0 );
        $potential_gross = (int) floor( $gross_base * $points_per_euro );
        $potential       = (int) floor( $eligible_total * $points_per_euro );
        $earned_meta_raw = $order->get_meta( '_epc_points_earned', true );
        $earned          = '' !== (string) $earned_meta_raw ? (int) $earned_meta_raw : null;

        // Build a single-sentence verdict.
        if ( $revoked ) {
            $verdict      = __( 'Οι πόντοι ακυρώθηκαν (cancelled/refunded).', 'epappous-club' );
            $verdict_pass = false;
        } elseif ( $settled && null !== $earned && $earned > 0 ) {
            $verdict = sprintf(
                /* translators: %d: earned points */
                __( 'Δόθηκαν %d πόντοι.', 'epappous-club' ),
                $earned
            );
            $verdict_pass = true;
        } elseif ( $settled && ( $earned === 0 || null === $earned ) ) {
            $verdict      = __( 'Έγινε υπολογισμός αλλά δεν προέκυψαν πόντοι (μηδενικό επιλέξιμο σύνολο ή ο πελάτης δεν είναι μέλος).', 'epappous-club' );
            $verdict_pass = false;
        } elseif ( ! $club_enabled ) {
            $verdict      = __( 'Το club είναι απενεργοποιημένο — δεν έγινε καν προσπάθεια υπολογισμού.', 'epappous-club' );
            $verdict_pass = false;
        } elseif ( ! $earn_enabled ) {
            $verdict      = __( 'Η απονομή πόντων από το WooCommerce είναι απενεργοποιημένη.', 'epappous-club' );
            $verdict_pass = false;
        } elseif ( ! $status_ok ) {
            $verdict = sprintf(
                /* translators: 1: current status, 2: eligible statuses */
                __( 'Το status «%1$s» δεν είναι μέσα στα επιλέξιμα (%2$s). Θα δοθούν %3$d πόντοι όταν περάσει σε επιλέξιμο status.', 'epappous-club' ),
                $current_status,
                implode( ', ', $allowed_statuses ),
                $potential
            );
            $verdict_pass = false;
        } elseif ( ! $in_club ) {
            $verdict      = __( 'Ο πελάτης δεν είναι σε B2B King Pappou Club group.', 'epappous-club' );
            $verdict_pass = false;
        } elseif ( ! $member_active ) {
            $verdict      = __( 'Δεν υπάρχει active γραμμή στον epc_members για αυτό το email.', 'epappous-club' );
            $verdict_pass = false;
        } elseif ( $potential < 1 ) {
            $verdict      = __( 'Δεν υπάρχουν επιλέξιμα προϊόντα ή το ποσό είναι πολύ μικρό για να δώσει πόντους.', 'epappous-club' );
            $verdict_pass = false;
        } else {
            $verdict = sprintf(
                /* translators: %d: potential points */
                __( 'Όλα ΟΚ — αναμένονται %d πόντοι. Πάτα «Recalculate Pappou Club points» αν δεν αποδόθηκαν αυτόματα.', 'epappous-club' ),
                $potential
            );
            $verdict_pass = true;
        }

        return [
            'checks'           => $checks,
            'items_breakdown'  => $items_breakdown,
            'items_gross'      => (float) $items_gross_total,
            'shipping_total'   => (float) $shipping_total,
            'shipping_counts'  => (bool) $shipping_in_gross,
            'gift_only_order'  => (bool) $gift_only_order,
            'points_discount'  => (float) $points_discount,
            'eligible_total'   => (float) $eligible_total,
            'points_per_euro'  => (float) $points_per_euro,
            'potential_gross'  => (int) $potential_gross,
            'potential'        => (int) $potential,
            'earned'           => $earned,
            'settled'          => (bool) $settled,
            'revoked'          => (bool) $revoked,
            'verdict'          => $verdict,
            'verdict_pass'     => (bool) $verdict_pass,
        ];
    }

    /**
     * Normalize metabox callback context to WC_Order instance.
     *
     * @param mixed $post_or_order WP_Post|WC_Order.
     */
    private function resolve_order_from_admin_context( $post_or_order ): ?\WC_Order {
        if ( $post_or_order instanceof \WC_Order ) {
            return $post_or_order;
        }
        if ( $post_or_order instanceof \WP_Post ) {
            return wc_get_order( (int) $post_or_order->ID );
        }
        if ( is_numeric( $post_or_order ) ) {
            return wc_get_order( (int) $post_or_order );
        }
        return null;
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

        $admin_email_ids    = [ 'new_order', 'cancelled_order', 'failed_order' ];
        $customer_email_ids = [
            'customer_processing_order',
            'customer_completed_order',
            'customer_on_hold_order',
            'customer_invoice',
        ];

        $email_id = (string) $email->id;
        $is_admin_recipient = $sent_to_admin || in_array( $email_id, $admin_email_ids, true );
        $is_known_customer  = in_array( $email_id, $customer_email_ids, true );

        if ( ! $is_admin_recipient && ! $is_known_customer ) {
            return;
        }

        $points = $this->get_points_earned_for_order_display( $order );

        $settled = $order->get_meta( '_epc_club_loyalty_settled', true ) === '1';
        $revoked = $order->get_meta( '_epc_points_revoked', true ) === '1';

        if ( $revoked ) {
            return;
        }

        $redeem_pts  = (int) $order->get_meta( '_epc_points_redeemed', true );
        $redeem_disc = (float) $order->get_meta( '_epc_discount_amount', true );
        $gift_pts    = 0;
        if ( class_exists( 'EPC_Gift_Products' ) ) {
            $gift_pts = (int) EPC_Gift_Products::order_gift_points_total( $order );
        }
        $total_redeemed_pts = $redeem_pts + $gift_pts;

        // Nothing to show? Bail.
        if ( null === $points && $redeem_pts < 1 && $gift_pts < 1 ) {
            return;
        }

        if ( $is_admin_recipient ) {
            $earn_label = $settled
                ? __( 'Πόντοι που κέρδισε ο πελάτης από αυτή την παραγγελία', 'epappous-club' )
                : __( 'Πόντοι που θα κερδίσει ο πελάτης όταν επιβεβαιωθεί η παραγγελία', 'epappous-club' );
            $total_redeem_label = __( 'Σύνολο πόντων που εξαργύρωσε ο πελάτης', 'epappous-club' );
            $redeem_label = __( 'Πόντοι που εξαργύρωσε ο πελάτης σε έκπτωση', 'epappous-club' );
            $gift_label   = __( 'Πόντοι που εξαργύρωσε ο πελάτης για δώρα', 'epappous-club' );
        } else {
            $earn_label = $settled
                ? __( 'Πόντοι από αυτή την παραγγελία', 'epappous-club' )
                : __( 'Πόντοι που θα κερδίσετε όταν επιβεβαιωθεί η παραγγελία', 'epappous-club' );
            $total_redeem_label = __( 'Σύνολο πόντων που εξαργυρώσατε', 'epappous-club' );
            $redeem_label = __( 'Πόντοι που εξαργυρώσατε σε έκπτωση', 'epappous-club' );
            $gift_label   = __( 'Πόντοι που εξαργυρώσατε για δώρα', 'epappous-club' );
        }

        $lines = [];

        if ( null !== $points ) {
            $lines[] = [ $earn_label, (string) (int) $points ];
        }

        if ( $total_redeemed_pts > 0 ) {
            $lines[] = [ $total_redeem_label, (string) $total_redeemed_pts ];
        }

        if ( $redeem_pts > 0 ) {
            $value = (string) $redeem_pts;
            if ( $redeem_disc > 0 ) {
                $value .= ' (−' . wc_format_decimal( $redeem_disc, 2 ) . ' €)';
            }
            $lines[] = [ $redeem_label, $value ];
        }

        if ( $gift_pts > 0 ) {
            $lines[] = [ $gift_label, (string) $gift_pts ];
        }

        if ( empty( $lines ) ) {
            return;
        }

        if ( $plain_text ) {
            foreach ( $lines as $line ) {
                echo "\n" . $line[0] . ': ' . $line[1] . "\n";
            }
            return;
        }

        foreach ( $lines as $line ) {
            echo '<p><strong>' . esc_html( $line[0] ) . ':</strong> ' . esc_html( $line[1] ) . '</p>';
        }
    }

    /**
     * Returns the "earned or potential" points for display purposes (emails / metaboxes).
     *
     * - If `_epc_points_earned` meta is already persisted (i.e. earning ran), use it.
     * - Otherwise return the value of `calculate_potential_points_for_order()`.
     * - Returns null when the order has no club-eligible customer.
     */
    private function get_points_earned_for_order_display( \WC_Order $order ): ?int {
        $meta = $order->get_meta( '_epc_points_earned', true );
        if ( '' !== (string) $meta ) {
            return (int) $meta;
        }

        if ( ! EPC_B2BKing::order_customer_in_pappou_club( $order ) ) {
            return null;
        }

        return $this->calculate_potential_points_for_order( $order );
    }

    /**
     * Pure calculation of how many points this order WOULD award based on current settings + items.
     * No side effects (no DB writes, no member balance changes, no order meta updates).
     *
     * Returns 0 for non-club orders or when nothing is eligible.
     */
    public function calculate_potential_points_for_order( \WC_Order $order ): int {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return 0;
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
                "SELECT id FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active' LIMIT 1",
                $email
            ),
            ARRAY_A
        );
        if ( ! $member ) {
            return 0;
        }

        $points_per_euro = (float) EPC_Settings::get( 'epc_points_per_euro' );
        $eligible_total  = $this->compute_eligible_order_total( $order );

        $raw_points = $eligible_total * $points_per_euro;
        $points     = (int) floor( $raw_points );

        return max( 0, $points );
    }

    /**
     * Calculate the € amount that should earn loyalty points for a given order.
     *
     * Rules (in order):
     *  - Sum line totals of items that are NOT excluded (sale/category exclusions).
     *  - If "Πόντοι στα μεταφορικά" is enabled, add the order shipping total.
     *  - Subtract the points-redemption discount stored on the order, so members
     *    don't earn points on the value they already paid for with points.
     *  - Clamped to >= 0 to avoid negative earnings when the discount exceeds the base.
     */
    private function compute_eligible_order_total( \WC_Order $order ): float {
        $exclude_sale      = EPC_Settings::get( 'epc_woo_exclude_sale_items' ) === '1';
        $exclude_cats_json = EPC_Settings::get( 'epc_woo_exclude_categories' );
        $exclude_cats      = json_decode( $exclude_cats_json, true ) ?: [];
        $include_shipping  = EPC_Settings::get( 'epc_woo_earn_include_shipping' ) === '1';

        $eligible_total = 0.0;
        $has_gift_item  = false;
        $has_paid_item  = false;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            // Gift products are bought with points only — they contribute €0 in the
            // line item too, but skip them explicitly so they never get accidentally
            // counted (e.g. if a future change starts pricing them as %0.00 strings).
            if ( class_exists( 'EPC_Gift_Products' ) && EPC_Gift_Products::is_gift_product( $product ) ) {
                $has_gift_item = true;
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
            $line_total      = (float) $item->get_total();
            $eligible_total += $line_total;
            if ( $line_total > 0 ) {
                $has_paid_item = true;
            }
        }

        // Add shipping only if there is at least one paid product to reward.
        // Rationale: in a gift-only order the customer pays just for shipping;
        // those euros shouldn't earn loyalty points (they already "paid" for
        // the gift in points). Mixed carts (gifts + paid products) keep
        // earning shipping points as before.
        if ( $include_shipping && ! ( $has_gift_item && ! $has_paid_item ) ) {
            $eligible_total += (float) $order->get_shipping_total();
        }

        $points_discount = (float) $order->get_meta( '_epc_discount_amount', true );
        if ( $points_discount > 0 ) {
            $eligible_total -= $points_discount;
        }

        return max( 0.0, $eligible_total );
    }

    /**
     * Persist `_epc_points_potential` on the order so it's always available for admin / emails.
     *
     * @param int|\WC_Order $order_or_id
     * @param bool          $force Recompute even if already stored.
     */
    public function ensure_potential_points_meta( $order_or_id, bool $force = false ): int {
        $order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
        if ( ! $order instanceof \WC_Order ) {
            return 0;
        }

        if ( ! $force ) {
            $stored = $order->get_meta( '_epc_points_potential', true );
            if ( '' !== (string) $stored ) {
                return (int) $stored;
            }
        }

        $points = $this->calculate_potential_points_for_order( $order );
        $order->update_meta_data( '_epc_points_potential', (int) $points );
        $order->save_meta_data();

        return (int) $points;
    }

    /**
     * Hook: persist potential points the moment an order is created.
     */
    public function maybe_persist_potential_points( $order_id ): void {
        $this->ensure_potential_points_meta( (int) $order_id );
    }

    /**
     * Hook: persist potential points for Block-checkout created orders.
     */
    public function maybe_persist_potential_points_blocks( $order ): void {
        if ( $order instanceof \WC_Order ) {
            $this->ensure_potential_points_meta( $order );
        }
    }

    /**
     * Hook: refresh potential meta on every status change while not yet settled.
     *
     * Once `_epc_club_loyalty_settled=1` exists we leave the recorded value alone — `_epc_points_earned`
     * is the source of truth from then on (and revocation is handled separately).
     */
    public function refresh_potential_points_on_status( $order_id, $from, $to, $order ): void {
        unset( $from, $to );
        if ( ! $order instanceof \WC_Order ) {
            $order = wc_get_order( (int) $order_id );
        }
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        if ( $order->get_meta( '_epc_club_loyalty_settled', true ) === '1' ) {
            return;
        }
        $this->ensure_potential_points_meta( $order, true );
    }

    /**
     * Manual recalculation helper for earned points only.
     * It safely removes previously awarded points/log/meta for this order, then re-runs earning logic.
     */
    private function recalculate_earned_points_for_order( \WC_Order $order ): string {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return __( 'Pappou Club: το club είναι απενεργοποιημένο, δεν έγινε επανυπολογισμός.', 'epappous-club' );
        }

        global $wpdb;
        $order_id       = (int) $order->get_id();
        $awarded_points = (int) $order->get_meta( '_epc_points_earned', true );
        $was_revoked    = $order->get_meta( '_epc_points_revoked', true ) === '1';

        // Undo prior awarded points if they were still applied to the member balance.
        if ( $awarded_points > 0 && ! $was_revoked ) {
            $email = sanitize_email( (string) $order->get_billing_email() );
            if ( is_email( $email ) ) {
                $member_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}epc_members WHERE email = %s LIMIT 1",
                        $email
                    )
                );
                if ( $member_id > 0 ) {
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$wpdb->prefix}epc_members SET points = GREATEST(points - %d, 0) WHERE id = %d",
                            $awarded_points,
                            $member_id
                        )
                    );
                    do_action( 'epc_points_changed', $member_id );
                }
            }
        }

        // Remove previous points-log rows for this order so the new run writes a clean result.
        $wpdb->delete(
            "{$wpdb->prefix}epc_points_log",
            [
                'reason'         => 'order_earning',
                'reference_type' => 'order',
                'reference_id'   => $order_id,
            ],
            [ '%s', '%s', '%d' ]
        );
        $wpdb->delete(
            "{$wpdb->prefix}epc_points_log",
            [
                'reason'         => 'order_reversal',
                'reference_type' => 'order',
                'reference_id'   => $order_id,
            ],
            [ '%s', '%s', '%d' ]
        );

        // Clear only earned-points meta so calculation can run fresh.
        foreach ( [
            '_epc_points_earned',
            '_epc_club_loyalty_settled',
            '_epc_points_revoked',
            '_epc_points_revoked_amount',
            '_epc_points_potential',
        ] as $meta_key ) {
            $order->delete_meta_data( $meta_key );
        }
        $order->save();

        // Refresh the potential first so admin metabox / emails always have something to show,
        // even if status isn't eligible yet.
        $this->ensure_potential_points_meta( $order, true );

        $this->earn_points_on_order( $order_id );

        // earn_points_on_order works on a freshly fetched order instance — reload to read updated meta.
        $fresh      = wc_get_order( $order_id );
        $new_points = $fresh instanceof \WC_Order ? (int) $fresh->get_meta( '_epc_points_earned', true ) : 0;
        if ( $new_points > 0 ) {
            return sprintf(
                /* translators: 1: order id, 2: points */
                __( 'Pappou Club: έγινε επανυπολογισμός για την παραγγελία #%1$d και αποδόθηκαν %2$d πόντοι.', 'epappous-club' ),
                $order_id,
                $new_points
            );
        }

        return sprintf(
            /* translators: %d: order id */
            __( 'Pappou Club: έγινε επανυπολογισμός για την παραγγελία #%d αλλά δεν προέκυψαν πόντοι.', 'epappous-club' ),
            $order_id
        );
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
        if ( ! $this->order_status_is_eligible_for_earning( $order ) ) {
            return;
        }
        if ( $order->get_meta( '_epc_club_loyalty_settled', true ) === '1' ) {
            return;
        }

        $in_club = EPC_B2BKing::order_customer_in_pappou_club( $order );

        global $wpdb;
        $member = null;
        $uid    = (int) $order->get_user_id();

        if ( $uid > 0 ) {
            $member = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_members WHERE user_id = %d AND status = 'active' LIMIT 1",
                    $uid
                ),
                ARRAY_A
            );
        }
        if ( ! $member && $uid < 1 ) {
            $email = sanitize_email( (string) $order->get_billing_email() );
            if ( is_email( $email ) ) {
                $member = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active' LIMIT 1",
                        $email
                    ),
                    ARRAY_A
                );
            }
        }

        if ( ! $in_club || ! $member ) {
            $order->update_meta_data( '_epc_club_loyalty_settled', '1' );
            $order->save();
            return;
        }

        $points_per_euro = (float) EPC_Settings::get( 'epc_points_per_euro' );
        $tier_multiplier = 1.0;
        $eligible_total  = $this->compute_eligible_order_total( $order );

        $raw_points = $eligible_total * $points_per_euro;
        $points     = (int) floor( $raw_points * $tier_multiplier );

        if ( $eligible_total <= 0 || $points < 1 ) {
            try {
                $order->update_meta_data( '_epc_points_earned', 0 );
                $order->update_meta_data( '_epc_club_loyalty_settled', '1' );
                $order->save();
            } catch ( Throwable $e ) {
                return;
            }
            return;
        }

        $already_logged = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}epc_points_log WHERE reference_type = %s AND reference_id = %d AND reason = %s LIMIT 1",
                'order',
                (int) $order_id,
                'order_earning'
            )
        );
        if ( $already_logged > 0 ) {
            try {
                $order->update_meta_data( '_epc_club_loyalty_settled', '1' );
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
            $order->save();
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_points_changed', (int) $member['id'] );
    }

    /**
     * Whether current order status is configured to award points.
     */
    private function order_status_is_eligible_for_earning( \WC_Order $order ): bool {
        $status = (string) $order->get_status();

        $allowed = json_decode( (string) EPC_Settings::get( 'epc_woo_earn_statuses' ), true );
        if ( ! is_array( $allowed ) || empty( $allowed ) ) {
            $allowed = [ 'completed' ];
        }
        $allowed = array_values(
            array_intersect( array_map( 'sanitize_key', $allowed ), [ 'processing', 'completed' ] )
        );
        if ( empty( $allowed ) ) {
            $allowed = [ 'completed' ];
        }

        return in_array( $status, $allowed, true );
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
        $member = null;
        $uid    = (int) $order->get_user_id();
        if ( $uid > 0 ) {
            $member = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE user_id = %d LIMIT 1",
                    $uid
                ),
                ARRAY_A
            );
        }
        if ( ! $member ) {
            $email = sanitize_email( (string) $order->get_billing_email() );
            if ( is_email( $email ) ) {
                $member = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE email = %s LIMIT 1",
                        $email
                    ),
                    ARRAY_A
                );
            }
        }
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
        $revoked_meta  = $order->get_meta( '_epc_points_revoked_amount', true );

        $gift_total = 0;
        if ( class_exists( 'EPC_Gift_Products' ) ) {
            $gift_total = EPC_Gift_Products::order_gift_points_total( $order );
        }

        if ( ! $settled && '' === (string) $earned_meta && '' === (string) $redeem && 0 === $gift_total ) {
            return;
        }

        if ( $settled ) {
            $earned_display = (string) (int) $order->get_meta( '_epc_points_earned', true );
        } elseif ( '' !== (string) $earned_meta ) {
            $earned_display = (string) (int) $earned_meta;
        } else {
            $earned_display = '—';
        }

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
                <?php esc_html_e( 'Πόντοι που αφαιρέθηκαν για δώρα στην παραγγελία:', 'epappous-club' ); ?>
                <strong><?php echo $gift_total > 0 ? esc_html( (string) $gift_total ) : '—'; ?></strong>
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
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE user_id = %d AND status = 'active' LIMIT 1",
                $user->ID
            )
        );

        if ( ! $member ) {
            return;
        }

        if ( ! EPC_B2BKing::user_in_pappou_club( (int) $user->ID ) ) {
            return;
        }

        // If the cart contains gift products and stacking is disabled, hide the
        // monetary redemption slider entirely (gifts already consume points).
        if ( class_exists( 'EPC_Gift_Products' )
            && EPC_Gift_Products::cart_gift_points_total() > 0
            && EPC_Settings::get( 'epc_woo_gift_allow_redeem_stack' ) !== '1' ) {
            return;
        }

        $min_points   = (int) EPC_Settings::get( 'epc_min_redeem_points' );
        $point_value  = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $max_percent  = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
        $member_pts   = (int) $member->points;
        $currency     = EPC_Settings::get( 'epc_currency_label' );
        $club_name    = EPC_Settings::get( 'epc_club_name' );
        $step_points  = 10;

        // Reserve points already committed to gift products in the cart so the
        // slider can never offer more than what the member can actually spend.
        $gift_pts_in_cart = class_exists( 'EPC_Gift_Products' )
            ? (int) EPC_Gift_Products::cart_gift_points_total()
            : 0;
        $available_pts    = max( 0, $member_pts - $gift_pts_in_cart );

        if ( $point_value <= 0 ) {
            return;
        }

        if ( $available_pts < max( $min_points, $step_points ) ) {
            return;
        }

        $cart_total    = (float) WC()->cart->get_subtotal();
        if ( $cart_total <= 0 ) {
            return;
        }

        $max_discount  = $cart_total * ( $max_percent / 100 );
        $max_from_pts  = $available_pts * $point_value;
        $max_usable    = min( $max_discount, $max_from_pts );
        $points_max    = (int) floor( $max_usable / $point_value );
        // Snap max down to the nearest step so the slider lands on a clean value.
        $points_max    = (int) ( floor( $points_max / $step_points ) * $step_points );

        $points_min = max( $step_points, $min_points );
        // If the cart can't afford even the minimum redemption (e.g. small order),
        // hide the slider entirely instead of forcing it down to the step.
        if ( $points_max < $points_min ) {
            return;
        }

        $already_applied = (float) WC()->session->get( 'epc_points_discount', 0 );
        $already_used    = (int) WC()->session->get( 'epc_points_used', 0 );
        $default_value   = $already_used > 0 ? $already_used : $points_min;
        ?>
        <tr class="epc-checkout-redeem">
            <td colspan="2" class="epc-redeem-cell">
                <div class="epc-redeem-box" data-min="<?php echo (int) $points_min; ?>" data-max="<?php echo (int) $points_max; ?>" data-step="<?php echo (int) $step_points; ?>" data-point-value="<?php echo esc_attr( (string) $point_value ); ?>" data-currency="<?php echo esc_attr( $currency ); ?>">
                    <div class="epc-redeem-header">
                        <h4 class="epc-redeem-title"><?php esc_html_e( 'Εξαργύρωσε τους πόντους σου σε χρήματα!', 'epappous-club' ); ?></h4>
                        <label class="epc-redeem-toggle">
                            <input type="checkbox" class="epc-redeem-toggle-input" <?php checked( $already_applied > 0 ); ?> />
                            <span class="epc-redeem-toggle-slider" aria-hidden="true"></span>
                        </label>
                    </div>

                    <div class="epc-redeem-meta">
                        <span class="epc-redeem-available">
                            <?php
                            if ( $gift_pts_in_cart > 0 ) {
                                printf(
                                    /* translators: 1: available points (after gift reservation), 2: points label, 3: reserved points, 4: total balance */
                                    esc_html__( '%1$s διαθέσιμοι %2$s (δεσμευμένοι %3$s για δώρα — σύνολο %4$s)', 'epappous-club' ),
                                    number_format_i18n( $available_pts ),
                                    esc_html( $currency ),
                                    number_format_i18n( $gift_pts_in_cart ),
                                    number_format_i18n( $member_pts )
                                );
                            } else {
                                printf(
                                    /* translators: 1: points balance, 2: points label (e.g. "πόντοι") */
                                    esc_html__( '%1$s διαθέσιμοι %2$s', 'epappous-club' ),
                                    number_format_i18n( $available_pts ),
                                    esc_html( $currency )
                                );
                            }
                            ?>
                        </span>
                        <span class="epc-redeem-current">
                            <strong class="epc-redeem-current-points"><?php echo (int) $default_value; ?></strong>
                            <span><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></span>
                        </span>
                    </div>

                    <input type="range" class="epc-redeem-slider" min="<?php echo (int) $points_min; ?>" max="<?php echo (int) $points_max; ?>" step="<?php echo (int) $step_points; ?>" value="<?php echo (int) $default_value; ?>" aria-label="<?php esc_attr_e( 'Επίλεξε πόντους προς εξαργύρωση', 'epappous-club' ); ?>" />

                    <div class="epc-redeem-footer">
                        <span class="epc-redeem-hint"><?php esc_html_e( 'Οι πόντοι αφαιρούνται απευθείας από την παραγγελία σου', 'epappous-club' ); ?></span>
                        <span class="epc-redeem-amount" data-prefix="-"><?php echo wp_kses_post( wc_price( $default_value * $point_value ) ); ?></span>
                    </div>

                    <div class="epc-redeem-actions">
                        <button type="button" class="epc-remove-points-btn"<?php echo $already_applied > 0 ? '' : ' style="display:none;"'; ?>><?php esc_html_e( 'Αφαίρεση πόντων', 'epappous-club' ); ?></button>
                    </div>

                    <p class="epc-redeem-foot-note">
                        <?php
                        printf(
                            /* translators: 1: max percent (e.g. 40), 2: club name */
                            esc_html__( 'Έως %1$d%% της αξίας της παραγγελίας — έκπτωση από το %2$s.', 'epappous-club' ),
                            (int) $max_percent,
                            esc_html( $club_name )
                        );
                        ?>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Render the referral link box on cart / checkout (encourage sharing).
     */
    public function render_referral_cart_box() {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_referral_enabled' ) !== '1' ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }

        $track_mem      = EPC_Settings::get( 'epc_referral_track_membership' ) === '1';
        $track_purchase = EPC_Settings::get( 'epc_referral_track_purchase' ) === '1';
        if ( ! $track_mem && ! $track_purchase ) {
            return;
        }

        global $wpdb;
        $user   = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, referral_code FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            )
        );

        if ( ! $member || empty( $member->referral_code ) ) {
            return;
        }

        $reward_ref   = (int) EPC_Settings::get( 'epc_referral_reward_referrer' );
        $club_name    = EPC_Settings::get( 'epc_club_name' );
        $share_link   = add_query_arg( 'ref', rawurlencode( $member->referral_code ), home_url( '/' ) );
        $email_subject = sprintf(
            /* translators: %s: club name */
            __( 'Έλα στο %s!', 'epappous-club' ),
            $club_name
        );
        $email_body = sprintf(
            /* translators: 1: club name, 2: share link */
            __( "Σου προτείνω να εγγραφείς στο %1\$s. Πάρε τους πόντους εγγραφής σου από αυτό το link:\n%2\$s", 'epappous-club' ),
            $club_name,
            $share_link
        );
        $mailto = 'mailto:?subject=' . rawurlencode( $email_subject ) . '&body=' . rawurlencode( $email_body );
        ?>
        <div class="epc-cart-referral-box">
            <p class="epc-cart-referral-lead">
                <?php
                if ( $track_mem && $reward_ref > 0 ) {
                    printf(
                        /* translators: 1: reward points, 2: club name */
                        wp_kses_post( __( '<strong>Κέρδισε extra Πόντους!</strong> Με κάθε νέα σύσταση, <strong>%1$s πόντοι</strong> μπαίνουν στον λογαριασμό σου μόλις ο φίλος σου εγγραφεί στο %2$s μέσω του referral link σου.', 'epappous-club' ) ),
                        number_format_i18n( $reward_ref ),
                        esc_html( $club_name )
                    );
                } else {
                    printf(
                        /* translators: %s: club name */
                        wp_kses_post( __( '<strong>Κέρδισε extra Πόντους!</strong> Μοιράσου το referral link σου και κέρδισε πόντους όταν ο φίλος σου εγγραφεί ή αγοράσει από το %s.', 'epappous-club' ) ),
                        esc_html( $club_name )
                    );
                }
                ?>
            </p>

            <div class="epc-cart-referral-row">
                <span class="epc-cart-referral-label"><?php esc_html_e( 'REFERRAL LINK', 'epappous-club' ); ?></span>
                <input type="text" readonly class="epc-cart-referral-input" id="epc-cart-ref-link" value="<?php echo esc_attr( $share_link ); ?>" aria-label="<?php esc_attr_e( 'Referral link', 'epappous-club' ); ?>" />
                <button type="button" class="epc-cart-referral-copy epc-copy-ref-link" data-copy="<?php echo esc_attr( $share_link ); ?>" title="<?php esc_attr_e( 'Αντιγραφή', 'epappous-club' ); ?>" aria-label="<?php esc_attr_e( 'Αντιγραφή', 'epappous-club' ); ?>">
                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                </button>
                <a class="epc-cart-referral-email" href="<?php echo esc_url( $mailto ); ?>" title="<?php esc_attr_e( 'Αποστολή με email', 'epappous-club' ); ?>" aria-label="<?php esc_attr_e( 'Αποστολή με email', 'epappous-club' ); ?>">
                    <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                </a>
            </div>

            <p class="epc-cart-referral-hint"><?php esc_html_e( 'Αντέγραψέ το ή στείλε το απευθείας με email σε έναν φίλο σου & δες τους πόντους να αυξάνονται!', 'epappous-club' ); ?></p>
        </div>
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
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE user_id = %d AND status = 'active' LIMIT 1",
                $user->ID
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
        $min_points   = (int) EPC_Settings::get( 'epc_min_redeem_points' );
        $step_points  = 10;
        $cart_total   = (float) WC()->cart->get_subtotal();

        if ( $point_value <= 0 || $cart_total <= 0 ) {
            wp_send_json_error();
        }

        // Reserve points already committed to gift products in the cart so the
        // monetary redemption can never spend more than what the member actually has.
        $gift_pts_in_cart = class_exists( 'EPC_Gift_Products' )
            ? (int) EPC_Gift_Products::cart_gift_points_total()
            : 0;
        $available_pts = max( 0, (int) $member->points - $gift_pts_in_cart );

        $max_discount = $cart_total * ( $max_percent / 100 );
        $max_from_pts = $available_pts * $point_value;
        $cap_discount = min( $max_discount, $max_from_pts );
        $cap_points   = (int) floor( $cap_discount / $point_value );
        $cap_points   = (int) ( floor( $cap_points / $step_points ) * $step_points );

        // Requested points (from slider). Falls back to "use max" if missing.
        $requested = isset( $_POST['points'] ) ? (int) $_POST['points'] : $cap_points;
        $requested = (int) ( floor( $requested / $step_points ) * $step_points );
        $floor_min = max( $step_points, $min_points );

        if ( $requested < $floor_min ) {
            wp_send_json_error( [ 'message' => __( 'Επίλεξε περισσότερους πόντους για να συνεχίσεις.', 'epappous-club' ) ] );
        }

        $pts_used = min( $requested, $cap_points );
        $discount = $pts_used * $point_value;

        if ( $pts_used < 1 || $discount <= 0 ) {
            wp_send_json_error();
        }

        WC()->session->set( 'epc_points_discount', $discount );
        WC()->session->set( 'epc_points_used', $pts_used );
        WC()->session->set( 'epc_points_member_id', (int) $member->id );

        wp_send_json_success(
            [
                'points'   => $pts_used,
                'discount' => $discount,
            ]
        );
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
     * Deduct points from member when order is placed with points discount (classic checkout).
     */
    public function record_points_redemption( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            $this->clear_points_discount_session();
            return;
        }
        $this->finalize_points_redemption_for_order( $order );
    }

    /**
     * Block checkout: settle redemption after the order is created (do not trust
     * the Store API extensions payload for identity or math — only session +
     * server recomputation against this order).
     */
    public function record_points_redemption_blocks_after_order( $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $this->finalize_points_redemption_for_order( $order );
    }

    /**
     * Legacy hook during block checkout request — debit happens in
     * record_points_redemption_blocks_after_order once the order exists.
     */
    public function record_points_redemption_from_blocks( $order, $request = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        if ( ! $order ) {
            $this->clear_points_discount_session();
        }
    }

    /**
     * Canonical member row for this order: linked WordPress user first,
     * billing email only as legacy fallback when no user is linked.
     *
     * @return array<string,mixed>|null
     */
    private function resolve_active_member_row_for_order( \WC_Order $order ): ?array {
        global $wpdb;

        $uid = (int) $order->get_user_id();
        if ( $uid > 0 ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_members WHERE user_id = %d AND status = 'active' LIMIT 1",
                    $uid
                ),
                ARRAY_A
            );
            if ( $row ) {
                return $row;
            }
        }

        $email = sanitize_email( (string) $order->get_billing_email() );
        if ( is_email( $email ) ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active' LIMIT 1",
                    $email
                ),
                ARRAY_A
            );
            return $row ? $row : null;
        }

        return null;
    }

    /**
     * Sum absolute EUR value of our negative checkout fee lines on the order.
     */
    private function get_club_points_fee_discount_total_from_order( \WC_Order $order ): float {
        $club = (string) EPC_Settings::get( 'epc_club_name' );
        $needle = sprintf(
            /* translators: %s: club name */
            __( 'Έκπτωση %s', 'epappous-club' ),
            $club
        );
        $total = 0.0;
        foreach ( $order->get_items( 'fee' ) as $fee ) {
            if ( ! $fee instanceof \WC_Order_Item_Fee ) {
                continue;
            }
            $name = (string) $fee->get_name();
            if ( $name !== $needle && strpos( $name, $needle ) === false ) {
                continue;
            }
            $t = (float) $fee->get_total();
            if ( $t < 0 ) {
                $total += abs( $t );
            }
        }
        return $total;
    }

    /**
     * Add a positive fee so the net effect of failed/mismatched points redemption
     * does not leave a discount without a matching points debit.
     */
    private function neutralize_club_points_discount_on_order( \WC_Order $order, float $discount_magnitude ): void {
        if ( $discount_magnitude <= 0.009 ) {
            return;
        }
        $item = new \WC_Order_Item_Fee();
        $item->set_name( __( 'Pappou Club: διόρθωση (η έκπτωση πόντων δεν οριστικοποιήθηκε)', 'epappous-club' ) );
        $item->set_amount( $discount_magnitude );
        $item->set_total( $discount_magnitude );
        $order->add_item( $item );
        $order->calculate_totals( false );
    }

    /**
     * If the fee applied on the order differs slightly from what we debited, align totals.
     */
    private function maybe_align_order_fee_to_debited_discount( \WC_Order $order, float $debited_discount_eur ): void {
        $applied = $this->get_club_points_fee_discount_total_from_order( $order );
        $delta   = round( $applied - $debited_discount_eur, 2 );
        if ( abs( $delta ) < 0.02 ) {
            return;
        }
        $item = new \WC_Order_Item_Fee();
        $item->set_name( __( 'Pappou Club: ευθυγράμμιση έκπτωσης πόντων', 'epappous-club' ) );
        $item->set_amount( $delta );
        $item->set_total( $delta );
        $order->add_item( $item );
        $order->calculate_totals( false );
    }

    /**
     * Server-side cap for points redemption for this order + member balance.
     *
     * @param array<string,mixed> $member_row Current member row from DB.
     * @return array{pts:int,discount:float}
     */
    private function recompute_allowed_points_redemption( \WC_Order $order, array $member_row ): array {
        $point_value = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $max_percent = (int) EPC_Settings::get( 'epc_max_redeem_percent' );
        $min_points  = (int) EPC_Settings::get( 'epc_min_redeem_points' );
        $step_points = 10;

        if ( $point_value <= 0 ) {
            return [ 'pts' => 0, 'discount' => 0.0 ];
        }

        $gift_pts = 0;
        if ( class_exists( 'EPC_Gift_Products' ) ) {
            $gift_pts = (int) EPC_Gift_Products::order_gift_points_total( $order );
        }

        $member_pts     = (int) $member_row['points'];
        $available_pts  = max( 0, $member_pts - $gift_pts );
        $cart_subtotal  = (float) $order->get_subtotal();
        if ( $cart_subtotal <= 0 ) {
            return [ 'pts' => 0, 'discount' => 0.0 ];
        }

        $max_discount = $cart_subtotal * ( $max_percent / 100 );
        $max_from_pts = $available_pts * $point_value;
        $cap_discount = min( $max_discount, $max_from_pts );
        $cap_points   = (int) floor( $cap_discount / $point_value );
        $cap_points   = (int) ( floor( $cap_points / $step_points ) * $step_points );

        $floor_min = max( $step_points, $min_points );
        if ( $cap_points < $floor_min ) {
            return [ 'pts' => 0, 'discount' => 0.0 ];
        }

        return [
            'pts'      => $cap_points,
            'discount' => round( $cap_points * $point_value, wc_get_price_decimals() ),
        ];
    }

    /**
     * Single entry: validate session vs order customer, recompute redemption,
     * debit points, or neutralize the cart discount on the order if we cannot debit.
     */
    private function finalize_points_redemption_for_order( \WC_Order $order ): void {
        if ( $order->get_meta( '_epc_points_redeemed', true ) ) {
            $this->clear_points_discount_session();
            return;
        }

        $fee_discount = $this->get_club_points_fee_discount_total_from_order( $order );
        $session_disc = WC()->session ? (float) WC()->session->get( 'epc_points_discount', 0 ) : 0.0;
        $session_pts  = WC()->session ? (int) WC()->session->get( 'epc_points_used', 0 ) : 0;
        $session_mid  = WC()->session ? (int) WC()->session->get( 'epc_points_member_id', 0 ) : 0;

        $has_intent = ( $fee_discount > 0.009 ) || ( $session_disc > 0 && $session_pts > 0 );
        if ( ! $has_intent ) {
            return;
        }

        $member_row = $this->resolve_active_member_row_for_order( $order );
        if ( ! $member_row || ! EPC_B2BKing::member_row_in_pappou_club( $member_row ) ) {
            $mag = max( $fee_discount, $session_disc );
            $this->neutralize_club_points_discount_on_order( $order, $mag );
            $order->add_order_note(
                __( 'ePappous Club: Η έκπτωση πόντων ακυρώθηκε — ο πελάτης δεν είναι μέλος Pappou Club (B2B King) ή δεν βρέθηκε ενεργό μέλος για αυτόν τον λογαριασμό.', 'epappous-club' )
            );
            $order->save();
            $this->clear_points_discount_session();
            return;
        }

        $canonical_id = (int) $member_row['id'];
        if ( $session_mid > 0 && $session_mid !== $canonical_id ) {
            $this->neutralize_club_points_discount_on_order( $order, max( $fee_discount, $session_disc ) );
            $order->add_order_note(
                __( 'ePappous Club: Η έκπτωση πόντων ακυρώθηκε — αναντιστοιχία session/λογαριασμού (απορρίφθηκε το αίτημα εξαργύρωσης).', 'epappous-club' )
            );
            $order->save();
            $this->clear_points_discount_session();
            return;
        }

        $cap = $this->recompute_allowed_points_redemption( $order, $member_row );
        if ( $cap['pts'] < 1 ) {
            $this->neutralize_club_points_discount_on_order( $order, max( $fee_discount, $session_disc ) );
            $order->add_order_note(
                __( 'ePappous Club: Η έκπτωση πόντων ακυρώθηκε — δεν πληρούνται πλέον οι κανόνες εξαργύρωσης (όριο παραγγελίας/πόντων).', 'epappous-club' )
            );
            $order->save();
            $this->clear_points_discount_session();
            return;
        }

        $point_value = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $step_points = 10;
        $floor_min   = max( $step_points, (int) EPC_Settings::get( 'epc_min_redeem_points' ) );

        $requested_pts = $session_pts > 0 ? (int) ( floor( $session_pts / $step_points ) * $step_points ) : (int) round( max( $fee_discount, $session_disc ) / $point_value );
        if ( $requested_pts < $floor_min ) {
            $requested_pts = $floor_min;
        }

        $final_pts = min( $requested_pts, $cap['pts'] );
        $final_pts = (int) ( floor( $final_pts / $step_points ) * $step_points );
        if ( $final_pts < $floor_min ) {
            $this->neutralize_club_points_discount_on_order( $order, max( $fee_discount, $session_disc ) );
            $order->add_order_note(
                __( 'ePappous Club: Η έκπτωση πόντων ακυρώθηκε — μη έγκυρο ποσό πόντων μετά τον επανέλεγχο.', 'epappous-club' )
            );
            $order->save();
            $this->clear_points_discount_session();
            return;
        }

        $final_discount = round( $final_pts * $point_value, wc_get_price_decimals() );
        $committed      = $this->commit_points_redemption( $order, $canonical_id, $final_pts, $final_discount );
        if ( $committed ) {
            $this->maybe_align_order_fee_to_debited_discount( $order, $final_discount );
            $order->save();
        }
        $this->clear_points_discount_session();
    }

    /**
     * Apply idempotent points deduction and log entry.
     * Caller must ensure member belongs to the order customer (resolved server-side).
     *
     * @return bool Whether points and meta were persisted successfully.
     */
    private function commit_points_redemption( $order, $member_id, $pts_used, $discount ): bool {
        if ( ! $order instanceof \WC_Order ) {
            return false;
        }

        if ( ! EPC_B2BKing::order_customer_in_pappou_club( $order ) ) {
            $this->neutralize_club_points_discount_on_order( $order, $this->get_club_points_fee_discount_total_from_order( $order ) );
            $order->add_order_note(
                __( 'ePappous Club: Εξαργύρωση πόντων δεν εφαρμόστηκε — ο λογαριασμός παραγγελίας δεν ανήκει στην ομάδα Pappou Club (B2B King).', 'epappous-club' )
            );
            $order->save();
            return false;
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
            $this->neutralize_club_points_discount_on_order( $order, $this->get_club_points_fee_discount_total_from_order( $order ) );
            $order->add_order_note(
                __( 'ePappous Club: Η αφαίρεση πόντων απέτυχε — η έκπτωση πόντων στην παραγγελία ακυρώθηκε για συνέπεια με το υπόλοιπο.', 'epappous-club' )
            );
            $order->save();
            return false;
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
            $this->neutralize_club_points_discount_on_order( $order, $this->get_club_points_fee_discount_total_from_order( $order ) );
            $order->add_order_note(
                __( 'ePappous Club: Αποτυχία καταγραφής εξαργύρωσης — η έκπτωση πόντων στην παραγγελία ακυρώθηκε.', 'epappous-club' )
            );
            $order->save();
            return false;
        }

        try {
            $order->update_meta_data( '_epc_points_redeemed', $pts_used );
            $order->update_meta_data( '_epc_discount_amount', $discount );
            $order->save();
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $this->neutralize_club_points_discount_on_order( $order, $this->get_club_points_fee_discount_total_from_order( $order ) );
            $order->add_order_note(
                __( 'ePappous Club: Αποτυχία αποθήκευσης μετα-δεδομένων — η έκπτωση πόντων στην παραγγελία ακυρώθηκε και καταγράφηκε διόρθωση συνόλου.', 'epappous-club' )
            );
            $order->save();
            return false;
        }

        $wpdb->query( 'COMMIT' );

        do_action( 'epc_points_changed', (int) $member_id );
        return true;
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

        wp_enqueue_script( 'epc-checkout-js', EPC_PLUGIN_URL . 'admin/js/checkout.js', [ 'jquery', 'wp-i18n' ], EPC_VERSION, true );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                'epc-checkout-js',
                'epappous-club',
                EPC_PLUGIN_DIR . 'languages'
            );
        }

        // Front-end styles are needed on both cart and checkout for the
        // redeem slider and the referral box.
        if ( EPC_Settings::get( 'epc_club_enabled' ) === '1' ) {
            wp_enqueue_style( 'dashicons' );
            wp_enqueue_style(
                'epc-front-css',
                EPC_PLUGIN_URL . 'admin/css/front.css',
                [ 'dashicons' ],
                EPC_VERSION
            );
        }

        $localized = [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'epc_front_nonce' ),
        ];
        if ( function_exists( 'is_checkout' ) && is_checkout() && EPC_Settings::get( 'epc_club_enabled' ) === '1' ) {
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

        $min_age = (int) EPC_Settings::get( 'epc_min_age' );
        $dob_chk = EPC_DOB_Validator::validate_club_dob(
            $dob,
            [
                'required' => true,
                'min_age'  => $min_age,
            ]
        );
        if ( is_wp_error( $dob_chk ) ) {
            $map     = [
                'epc_dob_invalid' => 'epc_club_dob_invalid',
                'epc_dob_age'     => 'epc_club_dob_age',
            ];
            $code    = $dob_chk->get_error_code();
            $errors->add(
                $map[ $code ] ?? 'epc_club_dob_invalid',
                $dob_chk->get_error_message()
            );
            return;
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

        $min_age = (int) EPC_Settings::get( 'epc_min_age' );
        $dob_chk = EPC_DOB_Validator::validate_club_dob(
            $dob,
            [
                'required' => true,
                'min_age'  => $min_age,
            ]
        );
        if ( is_wp_error( $dob_chk ) ) {
            wc_add_notice( $dob_chk->get_error_message(), 'error' );
            return;
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

        $min_age = (int) EPC_Settings::get( 'epc_min_age' );
        $dob_chk = EPC_DOB_Validator::validate_club_dob(
            $dob,
            [
                'required' => true,
                'min_age'  => $min_age,
            ]
        );
        if ( is_wp_error( $dob_chk ) ) {
            $order->update_meta_data( '_epc_checkout_club_processed', '1' );
            $order->add_order_note(
                __( 'Παππού Club: λείπει ή δεν είναι έγκυρη η ημερομηνία γέννησης — η εγγραφή δεν ολοκληρώθηκε.', 'epappous-club' )
                . ' ' . $dob_chk->get_error_message()
            );
            $order->save();
            return;
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
