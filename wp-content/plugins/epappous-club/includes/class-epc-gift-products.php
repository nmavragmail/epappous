<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Products in WooCommerce
 *
 * Lets the merchant designate ONE product category as the "Pappou Club Gifts"
 * category. Any WooCommerce product placed in that category becomes a
 * "buy-with-points-only" product:
 *
 *   - It cannot be paid with money — its monetary price is forced to 0 in the
 *     cart, and it is shown as "X πόντοι" everywhere on the storefront.
 *   - It earns no loyalty points (it has €0 line total, and is excluded from
 *     the eligible base used by EPC_WooCommerce::compute_eligible_order_total()).
 *   - Adding it to the cart requires the customer to be logged in, in the B2B
 *     King Pappou Club group, with at least the gift's points cost available
 *     (taking into account other gifts already in the cart).
 *   - The points cost is debited from the member ledger when the order moves
 *     to processing/completed (same hook used by the regular earn flow), and
 *     refunded back if the order is later cancelled or refunded.
 */
class EPC_Gift_Products {

    private static $instance = null;

    /** Product meta key — points required to redeem this gift. */
    const META_POINTS_COST = '_epc_gift_points_cost';

    /** Order item meta — total points debited for this line (unit_cost * qty). */
    const ITEM_META_GIFT_POINTS = '_epc_gift_points';

    /** Order item meta — per-unit points cost at the moment of purchase. */
    const ITEM_META_UNIT_POINTS = '_epc_gift_unit_points';

    /** Order meta — flag, gift points already debited from member balance. */
    const ORDER_META_SETTLED = '_epc_gift_points_settled';

    /** Order meta — flag, gift points already refunded back to member balance. */
    const ORDER_META_REFUNDED = '_epc_gift_points_refunded';

    /** Order meta — total gift points debited at settle time. */
    const ORDER_META_DEBITED_AMOUNT = '_epc_gift_points_debited';

    /** Order meta — total gift points refunded at revoke time. */
    const ORDER_META_REFUNDED_AMOUNT = '_epc_gift_points_refunded_amount';

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

        // ── Product edit metabox (single product page in wp-admin) ──
        add_action( 'add_meta_boxes', [ $this, 'register_product_metabox' ] );
        add_action( 'save_post_product', [ $this, 'save_product_metabox' ], 10, 2 );

        // ── Storefront price display ──
        add_filter( 'woocommerce_get_price_html', [ $this, 'filter_price_html' ], 20, 2 );
        add_filter( 'woocommerce_product_add_to_cart_text', [ $this, 'gift_add_to_cart_text' ], 20, 2 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'gift_add_to_cart_text' ], 20, 2 );
        add_filter( 'woocommerce_post_class', [ $this, 'gift_product_post_classes' ], 20, 3 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_storefront_assets' ] );

        // ── Cart: zero out the monetary price of gift products ──
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'zero_gift_prices_in_cart' ], 999 );

        // ── Cart row display ──
        add_filter( 'woocommerce_cart_item_price', [ $this, 'cart_item_price_html' ], 20, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'cart_item_subtotal_html' ], 20, 3 );

        // ── Add-to-cart validation ──
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 5 );

        // ── Checkout-time validation (and full-cart re-check) ──
        add_action( 'woocommerce_check_cart_items', [ $this, 'validate_cart_gift_points' ] );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_cart_gift_points' ] );

        // ── Cart totals: show how many points will be deducted ──
        add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'render_gift_points_total_row' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_gift_points_total_row' ] );

        // ── Snapshot points cost on each gift line item when the order is created ──
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'snapshot_gift_points_on_item' ], 10, 4 );

        // ── Spend / refund gift points in lockstep with the regular earn flow ──
        // Priority 25 ensures we run AFTER earn_points_on_order (priority 20) so
        // the regular earn calculation sees the unmodified ledger snapshot.
        add_action( 'woocommerce_order_status_processing', [ $this, 'spend_gift_points_on_order' ], 25 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'spend_gift_points_on_order' ], 25 );
        add_action( 'woocommerce_payment_complete', [ $this, 'spend_gift_points_on_order' ], 30 );

        add_action( 'woocommerce_order_status_cancelled', [ $this, 'refund_gift_points_on_order' ], 25 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'refund_gift_points_on_order' ], 25 );

        // ── Single product page: hide add-to-cart for non-eligible visitors ──
        add_filter( 'woocommerce_is_purchasable', [ $this, 'gate_purchasable_for_visitor' ], 20, 2 );
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Public helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * The single configured gift category term ID, or 0 if none is set.
     */
    public static function gift_category_id(): int {
        return (int) EPC_Settings::get( 'epc_woo_gift_category' );
    }

    /**
     * True if the given product belongs to the configured gift category.
     */
    public static function is_gift_product( $product ): bool {
        if ( ! $product instanceof \WC_Product ) {
            return false;
        }
        $cat_id = self::gift_category_id();
        if ( $cat_id < 1 ) {
            return false;
        }
        $lookup_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        $terms     = wp_get_post_terms( (int) $lookup_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return false;
        }
        return in_array( $cat_id, array_map( 'intval', $terms ), true );
    }

    /**
     * Configured points cost for a gift product (0 if none / not a gift).
     */
    public static function points_cost( $product ): int {
        if ( ! $product instanceof \WC_Product ) {
            return 0;
        }
        if ( ! self::is_gift_product( $product ) ) {
            return 0;
        }
        $lookup_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        $cost      = (int) get_post_meta( (int) $lookup_id, self::META_POINTS_COST, true );
        return max( 0, $cost );
    }

    /**
     * Sum of points the current cart will debit (unit_cost * qty for every gift line).
     */
    public static function cart_gift_points_total(): int {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }
        $sum = 0;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'] ?? null;
            $qty     = (int) ( $cart_item['quantity'] ?? 0 );
            $cost    = self::points_cost( $product );
            if ( $cost > 0 && $qty > 0 ) {
                $sum += $cost * $qty;
            }
        }
        return $sum;
    }

    /**
     * Current member balance for the logged-in user, or 0 if no member record.
     */
    public static function current_member_balance(): int {
        if ( ! is_user_logged_in() ) {
            return 0;
        }
        global $wpdb;
        $user = wp_get_current_user();
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                (int) $user->ID,
                (string) $user->user_email
            ),
            ARRAY_A
        );
        return $row ? (int) $row['points'] : 0;
    }

    /**
     * Balance that remains available after accounting for gift points already
     * committed in the cart.
     */
    public static function available_member_balance(): int {
        return max( 0, self::current_member_balance() - self::cart_gift_points_total() );
    }

    /**
     * True when the current visitor can redeem the given gift product right now.
     */
    public static function current_user_can_redeem_gift( $product, int $quantity = 1 ): bool {
        if ( ! $product instanceof \WC_Product || ! self::is_gift_product( $product ) ) {
            return false;
        }

        $cost = self::points_cost( $product );
        if ( $cost < 1 ) {
            return false;
        }

        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user_id = (int) get_current_user_id();
        if ( $user_id < 1 || ! EPC_B2BKing::user_in_pappou_club( $user_id ) ) {
            return false;
        }

        $needed = $cost * max( 1, $quantity );
        return self::available_member_balance() >= $needed;
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Product edit metabox
    // ────────────────────────────────────────────────────────────────────────

    public function register_product_metabox(): void {
        add_meta_box(
            'epc-gift-product-points',
            __( 'Pappou Club — Δώρο εξαργύρωσης', 'epappous-club' ),
            [ $this, 'render_product_metabox' ],
            'product',
            'side',
            'default'
        );
    }

    public function render_product_metabox( \WP_Post $post ): void {
        wp_nonce_field( 'epc_gift_points_save', 'epc_gift_points_nonce' );

        $cat_id = self::gift_category_id();
        if ( $cat_id < 1 ) {
            echo '<p style="margin:6px 0;">';
            esc_html_e( 'Δεν έχει οριστεί κατηγορία δώρου στις ρυθμίσεις. Όρισέ την στο Pappou Club → Ρυθμίσεις → WooCommerce.', 'epappous-club' );
            echo '</p>';
            return;
        }

        $product = wc_get_product( $post->ID );
        $is_gift = $product ? self::is_gift_product( $product ) : false;
        $cost    = (int) get_post_meta( $post->ID, self::META_POINTS_COST, true );

        $cat_term  = get_term( $cat_id, 'product_cat' );
        $cat_label = ( $cat_term && ! is_wp_error( $cat_term ) ) ? $cat_term->name : ( '#' . $cat_id );

        if ( ! $is_gift ) {
            echo '<p style="margin:6px 0;">';
            printf(
                /* translators: %s: gift category name */
                esc_html__( 'Πρόσθεσε αυτό το προϊόν στην κατηγορία «%s» για να ενεργοποιηθεί ως δώρο εξαργύρωσης.', 'epappous-club' ),
                esc_html( $cat_label )
            );
            echo '</p>';

            if ( $cost > 0 ) {
                echo '<p style="margin:6px 0;color:#9a1a1a;">';
                printf(
                    /* translators: %d: stored points cost */
                    esc_html__( 'Έχει αποθηκευμένο κόστος %d πόντων αλλά δεν είναι σε κατηγορία δώρου, οπότε η τιμή αγνοείται.', 'epappous-club' ),
                    (int) $cost
                );
                echo '</p>';
            }
            return;
        }

        echo '<p style="margin:6px 0;color:#1a7f37;">';
        printf(
            /* translators: %s: gift category name */
            esc_html__( 'Αυτό το προϊόν είναι στην κατηγορία δώρου «%s».', 'epappous-club' ),
            esc_html( $cat_label )
        );
        echo '</p>';

        echo '<p><label for="epc_gift_points_cost"><strong>';
        esc_html_e( 'Πόντοι για εξαργύρωση', 'epappous-club' );
        echo '</strong></label></p>';

        printf(
            '<input type="number" id="epc_gift_points_cost" name="epc_gift_points_cost" value="%s" min="0" step="1" style="width:100%%;" />',
            esc_attr( (string) $cost )
        );

        echo '<p class="description" style="margin-top:6px;">';
        esc_html_e( 'Πόσοι πόντοι αφαιρούνται από τον λογαριασμό του πελάτη για κάθε τεμάχιο. Αν 0, το προϊόν δεν μπορεί να αγοραστεί.', 'epappous-club' );
        echo '</p>';
        echo '<p class="description" style="margin-top:6px;color:#555;">';
        esc_html_e( 'Η χρηματική τιμή του προϊόντος αγνοείται αυτόματα στο cart/checkout (μηδενίζεται).', 'epappous-club' );
        echo '</p>';
    }

    public function save_product_metabox( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['epc_gift_points_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['epc_gift_points_nonce'] ) ), 'epc_gift_points_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( 'product' !== $post->post_type ) {
            return;
        }

        if ( isset( $_POST['epc_gift_points_cost'] ) ) {
            $cost = max( 0, (int) $_POST['epc_gift_points_cost'] );
            update_post_meta( $post_id, self::META_POINTS_COST, $cost );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Storefront price + cart display
    // ────────────────────────────────────────────────────────────────────────

    public function filter_price_html( $price_html, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $price_html;
        }
        if ( ! $product instanceof \WC_Product ) {
            return $price_html;
        }
        if ( ! self::is_gift_product( $product ) ) {
            return $price_html;
        }
        $cost = self::points_cost( $product );
        if ( $cost < 1 ) {
            return '<span class="epc-gift-price epc-gift-price--unavailable">' .
                esc_html__( 'Μη διαθέσιμο', 'epappous-club' ) .
                '</span>';
        }
        $label = EPC_Settings::get( 'epc_currency_label' );
        return '<span class="epc-gift-price">' .
            sprintf(
                /* translators: 1: points number, 2: points label (e.g. "πόντοι") */
                esc_html__( '%1$s %2$s', 'epappous-club' ),
                esc_html( number_format_i18n( $cost ) ),
                esc_html( $label )
            ) .
            '</span>';
    }

    public function gift_add_to_cart_text( $text, $product ) {
        if ( ! $product instanceof \WC_Product || ! self::is_gift_product( $product ) ) {
            return $text;
        }

        return __( 'ΕΞΑΡΓΥΡΩΣΕ ΤΟ!', 'epappous-club' );
    }

    public function gift_product_post_classes( array $classes, $product = null, $post_id = 0 ): array {
        if ( ! $product instanceof \WC_Product && $post_id > 0 ) {
            $product = wc_get_product( $post_id );
        }

        if ( ! $product instanceof \WC_Product || ! self::is_gift_product( $product ) ) {
            return $classes;
        }

        $classes[] = 'epc-gift-product';

        if ( ! self::current_user_can_redeem_gift( $product ) ) {
            $classes[] = 'epc-gift-product--locked';
        }

        return array_unique( $classes );
    }

    public function enqueue_storefront_assets(): void {
        if ( is_admin() ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        if ( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'epc-front-css',
            EPC_PLUGIN_URL . 'admin/css/front.css',
            [ 'dashicons' ],
            EPC_VERSION
        );
    }

    public function zero_gift_prices_in_cart( $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! $cart instanceof \WC_Cart ) {
            return;
        }
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'] ?? null;
            if ( $product instanceof \WC_Product && self::is_gift_product( $product ) ) {
                $product->set_price( 0 );
            }
        }
    }

    public function cart_item_price_html( $price_html, $cart_item, $cart_item_key ) {
        unset( $cart_item_key );
        $product = $cart_item['data'] ?? null;
        if ( ! $product instanceof \WC_Product || ! self::is_gift_product( $product ) ) {
            return $price_html;
        }
        $cost  = self::points_cost( $product );
        $label = EPC_Settings::get( 'epc_currency_label' );
        return '<span class="epc-gift-cart-price">' .
            sprintf(
                /* translators: 1: points number, 2: points label */
                esc_html__( '%1$s %2$s', 'epappous-club' ),
                esc_html( number_format_i18n( $cost ) ),
                esc_html( $label )
            ) .
            '</span>';
    }

    public function cart_item_subtotal_html( $subtotal_html, $cart_item, $cart_item_key ) {
        unset( $cart_item_key );
        $product = $cart_item['data'] ?? null;
        if ( ! $product instanceof \WC_Product || ! self::is_gift_product( $product ) ) {
            return $subtotal_html;
        }
        $cost  = self::points_cost( $product );
        $qty   = (int) ( $cart_item['quantity'] ?? 0 );
        $total = $cost * max( 0, $qty );
        $label = EPC_Settings::get( 'epc_currency_label' );
        return '<span class="epc-gift-cart-subtotal">' .
            sprintf(
                /* translators: 1: points number, 2: points label */
                esc_html__( '%1$s %2$s', 'epappous-club' ),
                esc_html( number_format_i18n( $total ) ),
                esc_html( $label )
            ) .
            '</span>';
    }

    public function render_gift_points_total_row(): void {
        $gift_points = self::cart_gift_points_total();

        // Also account for the monetary redemption slider so the row shows the
        // TOTAL points the order will debit (gifts + slider redemption), not
        // just the gift portion.
        $redeem_points = 0;
        if ( function_exists( 'WC' ) && WC()->session ) {
            $redeem_points = (int) WC()->session->get( 'epc_points_used', 0 );
        }

        $total = $gift_points + $redeem_points;
        if ( $total < 1 ) {
            return;
        }

        $label    = EPC_Settings::get( 'epc_currency_label' );
        $balance  = self::current_member_balance();
        $shortage = $balance - $total;
        ?>
        <tr class="epc-gift-points-row">
            <th><?php esc_html_e( 'Πόντοι που θα αφαιρεθούν', 'epappous-club' ); ?></th>
            <td data-title="<?php esc_attr_e( 'Πόντοι που θα αφαιρεθούν', 'epappous-club' ); ?>">
                <strong>
                    <?php
                    printf(
                        /* translators: 1: points number, 2: points label */
                        esc_html__( '−%1$s %2$s', 'epappous-club' ),
                        esc_html( number_format_i18n( $total ) ),
                        esc_html( $label )
                    );
                    ?>
                </strong>
                <?php if ( $gift_points > 0 && $redeem_points > 0 ) : ?>
                    <br>
                    <small style="color:#555;">
                        <?php
                        printf(
                            /* translators: 1: gift points, 2: redeem points, 3: points label */
                            esc_html__( 'Δώρα: %1$s · Έκπτωση πόντων: %2$s %3$s', 'epappous-club' ),
                            esc_html( number_format_i18n( $gift_points ) ),
                            esc_html( number_format_i18n( $redeem_points ) ),
                            esc_html( $label )
                        );
                        ?>
                    </small>
                <?php endif; ?>
                <br>
                <small style="color:#555;">
                    <?php
                    if ( ! is_user_logged_in() ) {
                        esc_html_e( 'Συνδέσου ως μέλος για να ολοκληρωθεί η εξαργύρωση.', 'epappous-club' );
                    } elseif ( $shortage < 0 ) {
                        printf(
                            /* translators: 1: missing points, 2: points label */
                            esc_html__( 'Σου λείπουν %1$s %2$s για να ολοκληρώσεις.', 'epappous-club' ),
                            esc_html( number_format_i18n( abs( $shortage ) ) ),
                            esc_html( $label )
                        );
                    } else {
                        printf(
                            /* translators: 1: balance after, 2: points label */
                            esc_html__( 'Υπόλοιπο μετά: %1$s %2$s', 'epappous-club' ),
                            esc_html( number_format_i18n( $shortage ) ),
                            esc_html( $label )
                        );
                    }
                    ?>
                </small>
            </td>
        </tr>
        <?php
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Validation
    // ────────────────────────────────────────────────────────────────────────

    public function gate_purchasable_for_visitor( $purchasable, $product ) {
        if ( ! $product instanceof \WC_Product || ! self::is_gift_product( $product ) ) {
            return $purchasable;
        }

        // Storefront CTA is visible only when this gift can actually be
        // redeemed. Validation still runs later for defense in depth.
        return self::current_user_can_redeem_gift( $product );
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
        unset( $variations );

        $real_id = $variation_id > 0 ? (int) $variation_id : (int) $product_id;
        $product = wc_get_product( $real_id );
        if ( ! $product instanceof \WC_Product ) {
            return $passed;
        }
        if ( ! self::is_gift_product( $product ) ) {
            return $passed;
        }

        $cost = self::points_cost( $product );
        if ( $cost < 1 ) {
            wc_add_notice(
                __( 'Αυτό το δώρο δεν είναι διαθέσιμο αυτή τη στιγμή.', 'epappous-club' ),
                'error'
            );
            return false;
        }

        if ( ! is_user_logged_in() ) {
            wc_add_notice(
                __( 'Πρέπει να συνδεθείς ως μέλος του Pappou Club για να εξαργυρώσεις δώρα με πόντους.', 'epappous-club' ),
                'error'
            );
            return false;
        }

        if ( ! EPC_B2BKing::user_in_pappou_club( (int) get_current_user_id() ) ) {
            wc_add_notice(
                __( 'Δεν είσαι μέλος του Pappou Club, οπότε δεν μπορείς να εξαργυρώσεις πόντους για δώρα.', 'epappous-club' ),
                'error'
            );
            return false;
        }

        $balance        = self::current_member_balance();
        $already_in_cart = self::cart_gift_points_total();
        $needed          = $cost * max( 1, (int) $quantity );

        if ( ( $already_in_cart + $needed ) > $balance ) {
            $label = EPC_Settings::get( 'epc_currency_label' );
            wc_add_notice(
                sprintf(
                    /* translators: 1: needed points, 2: balance, 3: points label */
                    __( 'Δεν έχεις αρκετούς πόντους για αυτό το δώρο. Απαιτούνται %1$s %3$s, διαθέσιμοι %2$s %3$s.', 'epappous-club' ),
                    number_format_i18n( $already_in_cart + $needed ),
                    number_format_i18n( $balance ),
                    $label
                ),
                'error'
            );
            return false;
        }

        return $passed;
    }

    public function validate_cart_gift_points(): void {
        $needed = self::cart_gift_points_total();
        if ( $needed < 1 ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            wc_add_notice(
                __( 'Συνδέσου ως μέλος του Pappou Club για να ολοκληρώσεις την παραγγελία με δώρα.', 'epappous-club' ),
                'error'
            );
            return;
        }

        if ( ! EPC_B2BKing::user_in_pappou_club( (int) get_current_user_id() ) ) {
            wc_add_notice(
                __( 'Δεν είσαι μέλος του Pappou Club, αφαίρεσε τα δώρα από το καλάθι σου.', 'epappous-club' ),
                'error'
            );
            return;
        }

        $balance = self::current_member_balance();
        if ( $needed > $balance ) {
            $label = EPC_Settings::get( 'epc_currency_label' );
            wc_add_notice(
                sprintf(
                    /* translators: 1: needed points, 2: available balance, 3: label */
                    __( 'Σου λείπουν πόντοι για τα δώρα στο καλάθι. Απαιτούνται %1$s %3$s, έχεις %2$s %3$s.', 'epappous-club' ),
                    number_format_i18n( $needed ),
                    number_format_i18n( $balance ),
                    $label
                ),
                'error'
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Order persistence + ledger transactions
    // ────────────────────────────────────────────────────────────────────────

    public function snapshot_gift_points_on_item( $item, $cart_item_key, $values, $order ): void {
        unset( $cart_item_key, $order );
        if ( ! $item instanceof \WC_Order_Item_Product ) {
            return;
        }
        $product = $values['data'] ?? null;
        if ( ! $product instanceof \WC_Product || ! self::is_gift_product( $product ) ) {
            return;
        }
        $unit_cost = self::points_cost( $product );
        if ( $unit_cost < 1 ) {
            return;
        }
        $qty   = (int) ( $values['quantity'] ?? $item->get_quantity() );
        $total = $unit_cost * max( 0, $qty );
        $item->add_meta_data( self::ITEM_META_UNIT_POINTS, $unit_cost, true );
        $item->add_meta_data( self::ITEM_META_GIFT_POINTS, $total, true );
    }

    /**
     * Sum points cost across all gift line items recorded on the order.
     */
    public static function order_gift_points_total( \WC_Order $order ): int {
        $total = 0;
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }
            $stored = (int) $item->get_meta( self::ITEM_META_GIFT_POINTS, true );
            if ( $stored > 0 ) {
                $total += $stored;
                continue;
            }
            // Fallback: live recompute from product meta if line meta is missing
            // (e.g. very old orders, or admin-added items).
            $product = $item->get_product();
            if ( $product && self::is_gift_product( $product ) ) {
                $unit  = self::points_cost( $product );
                $qty   = (int) $item->get_quantity();
                $total += $unit * max( 0, $qty );
            }
        }
        return $total;
    }

    public function spend_gift_points_on_order( $order_id ): void {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        if ( $order->get_meta( self::ORDER_META_SETTLED, true ) === '1' ) {
            return;
        }

        $needed = self::order_gift_points_total( $order );
        if ( $needed < 1 ) {
            $order->update_meta_data( self::ORDER_META_SETTLED, '1' );
            $order->save_meta_data();
            return;
        }

        global $wpdb;
        $member = null;
        $uid    = (int) $order->get_user_id();
        if ( $uid > 0 ) {
            $member = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, user_id, points FROM {$wpdb->prefix}epc_members WHERE user_id = %d AND status = 'active' LIMIT 1",
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
                        "SELECT id, user_id, points FROM {$wpdb->prefix}epc_members WHERE email = %s AND status = 'active' LIMIT 1",
                        $email
                    ),
                    ARRAY_A
                );
            }
        }

        if ( ! $member || ! EPC_B2BKing::member_row_in_pappou_club( $member ) ) {
            $order->add_order_note( __( 'Pappou Club: αδυναμία αφαίρεσης πόντων δώρου — δεν βρέθηκε ενεργό μέλος Pappou Club (B2B King) για αυτόν τον πελάτη.', 'epappous-club' ) );
            $order->update_status( 'on-hold', __( 'Pappou Club: δώρα χωρίς έγκυρο μέλος — ελέγξτε τον λογαριασμό πελάτη.', 'epappous-club' ) );
            return;
        }

        $member_id = (int) $member['id'];
        $balance   = (int) $member['points'];

        // All-or-nothing: never partially debit gift points.
        if ( $balance < $needed ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: needed points, 2: current balance */
                    __( 'Pappou Club: η παραγγελία απαιτεί %1$d πόντους για δώρα αλλά το διαθέσιμο υπόλοιπο είναι %2$d. Δεν αφαιρέθηκαν πόντοι — απαιτείται διόρθωση πριν την ολοκλήρωση.', 'epappous-club' ),
                    $needed,
                    $balance
                )
            );
            $order->update_status( 'on-hold', __( 'Pappou Club: ανεπαρκές υπόλοιπο για δώρα με πόντους.', 'epappous-club' ) );
            return;
        }

        $debit = $needed;

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = points - %d WHERE id = %d AND points >= %d",
                $debit,
                $member_id,
                $debit
            )
        );
        if ( 1 !== $updated ) {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note( __( 'Pappou Club: η αφαίρεση πόντων δώρου απέτυχε (σύγκρουση υπολοίπου).', 'epappous-club' ) );
            return;
        }

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => -$debit,
                'reason'         => 'gift_redemption',
                'reference_type' => 'order',
                'reference_id'   => (int) $order_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        try {
            $order->update_meta_data( self::ORDER_META_SETTLED, '1' );
            $order->update_meta_data( self::ORDER_META_DEBITED_AMOUNT, $debit );
            $order->save();
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $wpdb->query( 'COMMIT' );

        $order->add_order_note(
            sprintf(
                /* translators: %d: points debited */
                __( 'Pappou Club: αφαιρέθηκαν %d πόντοι για τα δώρα της παραγγελίας.', 'epappous-club' ),
                $debit
            )
        );

        do_action( 'epc_points_changed', $member_id );
    }

    public function refund_gift_points_on_order( $order_id ): void {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        if ( $order->get_meta( self::ORDER_META_REFUNDED, true ) === '1' ) {
            return;
        }
        if ( $order->get_meta( self::ORDER_META_SETTLED, true ) !== '1' ) {
            // We never debited anything for this order — nothing to refund.
            return;
        }

        $debited = (int) $order->get_meta( self::ORDER_META_DEBITED_AMOUNT, true );
        if ( $debited < 1 ) {
            $order->update_meta_data( self::ORDER_META_REFUNDED, '1' );
            $order->save_meta_data();
            return;
        }

        global $wpdb;
        $member = null;
        $uid    = (int) $order->get_user_id();
        if ( $uid > 0 ) {
            $member = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}epc_members WHERE user_id = %d LIMIT 1",
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
                        "SELECT id FROM {$wpdb->prefix}epc_members WHERE email = %s LIMIT 1",
                        $email
                    ),
                    ARRAY_A
                );
            }
        }
        if ( ! $member ) {
            return;
        }
        $member_id = (int) $member['id'];

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                $debited,
                $member_id
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
                'points'         => $debited,
                'reason'         => 'gift_refund',
                'reference_type' => 'order',
                'reference_id'   => (int) $order_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        try {
            $order->update_meta_data( self::ORDER_META_REFUNDED, '1' );
            $order->update_meta_data( self::ORDER_META_REFUNDED_AMOUNT, $debited );
            $order->save();
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }

        $wpdb->query( 'COMMIT' );

        $order->add_order_note(
            sprintf(
                /* translators: %d: refunded points */
                __( 'Pappou Club: επιστράφηκαν %d πόντοι δώρου λόγω ακύρωσης/επιστροφής.', 'epappous-club' ),
                $debited
            )
        );

        do_action( 'epc_points_changed', $member_id );
    }
}
