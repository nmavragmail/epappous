<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Catalog (front-end)
 *
 * Shortcode [epappous_gifts] displays gifts resolved from Gift Rules.
 * Products come from WooCommerce, filtered by rules (product/category/tag).
 */
class EPC_Gift_Catalog {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'epappous_gifts', [ $this, 'render_catalog' ] );
        add_action( 'wp_ajax_epc_redeem_gift', [ $this, 'ajax_redeem' ] );
    }

    public function render_catalog( $atts ) {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return '<p>' . esc_html__( 'Το club δεν είναι ενεργό.', 'epappous-club' ) . '</p>';
        }
        if ( EPC_Settings::get( 'epc_gifts_enabled' ) !== '1' ) {
            return '<p>' . esc_html__( 'Τα δώρα δεν είναι ενεργά αυτή τη στιγμή.', 'epappous-club' ) . '</p>';
        }
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Πρέπει να συνδεθείς για να δεις τα δώρα.', 'epappous-club' ) . '</p>';
        }
        if ( ! function_exists( 'wc_get_product' ) ) {
            return '<p>' . esc_html__( 'Απαιτείται WooCommerce.', 'epappous-club' ) . '</p>';
        }

        global $wpdb;
        $user   = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            ),
            ARRAY_A
        );

        if ( ! $member ) {
            return '<p>' . esc_html__( 'Δεν είσαι μέλος του club.', 'epappous-club' ) . '</p>';
        }

        $gift_products = EPC_Gift_Rules::resolve_products( $member['tier'] );

        $show_stock = EPC_Settings::get( 'epc_gifts_show_stock' ) === '1';
        $currency   = esc_html( EPC_Settings::get( 'epc_currency_symbol' ) );
        $my_points  = (int) $member['points'];
        $nonce      = wp_create_nonce( 'epc_front_nonce' );

        ob_start();
        ?>
        <div class="epc-gifts-catalog">
            <div class="epc-gifts-header">
                <h2><?php esc_html_e( 'Δώρα', 'epappous-club' ); ?></h2>
                <span class="epc-my-points">
                    <?php printf(
                        esc_html__( 'Οι πόντοι σου: %s %s', 'epappous-club' ),
                        $currency,
                        number_format( $my_points )
                    ); ?>
                </span>
            </div>

            <div id="epc-gift-catalog-messages"></div>

            <?php if ( empty( $gift_products ) ) : ?>
                <p class="epc-no-gifts"><?php esc_html_e( 'Δεν υπάρχουν διαθέσιμα δώρα για τη βαθμίδα σου.', 'epappous-club' ); ?></p>
            <?php else : ?>
                <div class="epc-gifts-grid-front">
                    <?php foreach ( $gift_products as $product_id => $cfg ) :
                        $product = wc_get_product( $product_id );
                        if ( ! $product ) continue;

                        $can_afford = $my_points >= $cfg['points_required'];
                        $in_stock   = $product->is_in_stock();
                        $image_url  = wp_get_attachment_url( $product->get_image_id() );
                    ?>
                        <div class="epc-gift-item <?php echo ! $can_afford ? 'epc-gift-locked' : ''; ?> <?php echo ! $in_stock ? 'epc-gift-sold-out' : ''; ?>">
                            <?php if ( $image_url ) : ?>
                                <div class="epc-gift-img"><img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" /></div>
                            <?php endif; ?>
                            <div class="epc-gift-info">
                                <h3><?php echo esc_html( $product->get_name() ); ?></h3>
                                <p><?php echo esc_html( wp_trim_words( $product->get_short_description(), 20 ) ); ?></p>
                                <div class="epc-gift-cost">
                                    <?php echo esc_html( $currency . ' ' . number_format( $cfg['points_required'] ) ); ?>
                                </div>

                                <?php if ( $show_stock && ! $product->managing_stock() ) : ?>
                                <?php elseif ( $show_stock && $product->managing_stock() ) : ?>
                                    <div class="epc-gift-stock-info">
                                        <?php printf( esc_html__( 'Απόθεμα: %d', 'epappous-club' ), $product->get_stock_quantity() ); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $in_stock && $can_afford ) : ?>
                                    <button type="button" class="epc-btn-primary epc-redeem-btn"
                                            data-gift-id="<?php echo (int) $product_id; ?>"
                                            data-rule-id="<?php echo (int) $cfg['rule_id']; ?>"
                                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                        <?php esc_html_e( 'Εξαργύρωση', 'epappous-club' ); ?>
                                    </button>
                                <?php elseif ( ! $in_stock ) : ?>
                                    <button class="epc-btn-primary" disabled><?php esc_html_e( 'Εξαντλήθηκε', 'epappous-club' ); ?></button>
                                <?php else : ?>
                                    <button class="epc-btn-primary" disabled>
                                        <?php printf( esc_html__( 'Χρειάζεσαι %s ακόμα', 'epappous-club' ),
                                            $currency . ' ' . number_format( $cfg['points_required'] - $my_points )
                                        ); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: redeem a gift product via rules.
     */
    public function ajax_redeem() {
        check_ajax_referer( 'epc_front_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'Πρέπει να συνδεθείς.', 'epappous-club' ) );
        }
        if ( EPC_Settings::get( 'epc_gifts_enabled' ) !== '1' ) {
            wp_send_json_error( __( 'Τα δώρα δεν είναι ενεργά.', 'epappous-club' ) );
        }

        global $wpdb;
        $user   = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            ),
            ARRAY_A
        );

        if ( ! $member ) {
            wp_send_json_error( __( 'Δεν βρέθηκε μέλος.', 'epappous-club' ) );
        }

        $product_id = (int) ( $_POST['gift_id'] ?? 0 );
        $rule_id    = (int) ( $_POST['rule_id'] ?? 0 );

        // Verify the rule exists and is active
        $rule = EPC_Gift_Rules::get( $rule_id );
        if ( ! $rule || ! $rule['is_active'] ) {
            wp_send_json_error( __( 'Ο κανόνας δεν είναι ενεργός.', 'epappous-club' ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_in_stock() ) {
            wp_send_json_error( __( 'Το προϊόν δεν είναι διαθέσιμο.', 'epappous-club' ) );
        }

        $points_needed = (int) $rule['points_required'];
        if ( (int) $member['points'] < $points_needed ) {
            wp_send_json_error( __( 'Δεν έχεις αρκετούς πόντους.', 'epappous-club' ) );
        }

        // Deduct points
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = GREATEST(0, CAST(points AS SIGNED) - %d) WHERE id = %d",
                $points_needed,
                (int) $member['id']
            )
        );

        // Log
        $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => (int) $member['id'],
                'points'         => -$points_needed,
                'reason'         => 'gift_redemption',
                'reference_type' => 'product',
                'reference_id'   => $product_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );

        // Record redemption
        $wpdb->insert(
            "{$wpdb->prefix}epc_gift_redemptions",
            [
                'member_id'       => (int) $member['id'],
                'gift_product_id' => $product_id,
                'points_spent'    => $points_needed,
                'status'          => 'pending',
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        $redemption_id = (int) $wpdb->insert_id;

        do_action( 'epc_gift_redeemed', (int) $member['id'], $product_id, $redemption_id );
        do_action( 'epc_points_changed', (int) $member['id'] );

        wp_send_json_success( __( 'Το δώρο εξαργυρώθηκε επιτυχώς!', 'epappous-club' ) );
    }
}
