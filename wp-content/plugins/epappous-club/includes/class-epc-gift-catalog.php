<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Catalog (front-end)
 *
 * Shortcode [epappous_gifts] displays the gift catalog for logged-in members.
 * Members can redeem gifts using their points.
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

        $per_page = (int) EPC_Settings::get( 'epc_gifts_per_page' );
        $page     = max( 1, (int) ( $_GET['gift_page'] ?? 1 ) ); // phpcs:ignore

        $gifts = EPC_Gifts::get_all( [
            'active_only' => true,
            'tier'        => $member['tier'],
            'per_page'    => $per_page,
            'page'        => $page,
        ] );

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

            <?php if ( empty( $gifts ) ) : ?>
                <p class="epc-no-gifts"><?php esc_html_e( 'Δεν υπάρχουν διαθέσιμα δώρα για τη βαθμίδα σου.', 'epappous-club' ); ?></p>
            <?php else : ?>
                <div class="epc-gifts-grid-front">
                    <?php foreach ( $gifts as $gift ) :
                        $can_afford = $my_points >= (int) $gift['points_required'];
                        $in_stock   = (int) $gift['stock'] !== 0;
                    ?>
                        <div class="epc-gift-item <?php echo ! $can_afford ? 'epc-gift-locked' : ''; ?> <?php echo ! $in_stock ? 'epc-gift-sold-out' : ''; ?>">
                            <?php if ( ! empty( $gift['image_url'] ) ) : ?>
                                <div class="epc-gift-img"><img src="<?php echo esc_url( $gift['image_url'] ); ?>" alt="<?php echo esc_attr( $gift['title'] ); ?>" /></div>
                            <?php endif; ?>
                            <div class="epc-gift-info">
                                <h3><?php echo esc_html( $gift['title'] ); ?></h3>
                                <?php if ( ! empty( $gift['description'] ) ) : ?>
                                    <p><?php echo esc_html( $gift['description'] ); ?></p>
                                <?php endif; ?>
                                <div class="epc-gift-cost">
                                    <?php echo esc_html( $currency . ' ' . number_format( (int) $gift['points_required'] ) ); ?>
                                </div>
                                <?php if ( $show_stock && (int) $gift['stock'] >= 0 ) : ?>
                                    <div class="epc-gift-stock-info">
                                        <?php
                                        if ( (int) $gift['stock'] === 0 ) {
                                            esc_html_e( 'Εξαντλήθηκε', 'epappous-club' );
                                        } else {
                                            printf( esc_html__( 'Απόθεμα: %d', 'epappous-club' ), (int) $gift['stock'] );
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $in_stock && $can_afford ) : ?>
                                    <button type="button" class="epc-btn-primary epc-redeem-btn"
                                            data-gift-id="<?php echo (int) $gift['id']; ?>"
                                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                        <?php esc_html_e( 'Εξαργύρωση', 'epappous-club' ); ?>
                                    </button>
                                <?php elseif ( ! $in_stock ) : ?>
                                    <button class="epc-btn-primary" disabled><?php esc_html_e( 'Εξαντλήθηκε', 'epappous-club' ); ?></button>
                                <?php else : ?>
                                    <button class="epc-btn-primary" disabled>
                                        <?php printf( esc_html__( 'Χρειάζεσαι %s ακόμα', 'epappous-club' ),
                                            $currency . ' ' . number_format( (int) $gift['points_required'] - $my_points )
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
     * AJAX: redeem a gift.
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
                "SELECT id FROM {$wpdb->prefix}epc_members WHERE (user_id = %d OR email = %s) AND status = 'active' LIMIT 1",
                $user->ID,
                $user->user_email
            )
        );

        if ( ! $member ) {
            wp_send_json_error( __( 'Δεν βρέθηκε μέλος.', 'epappous-club' ) );
        }

        $gift_id = (int) ( $_POST['gift_id'] ?? 0 );
        $result  = EPC_Gifts::redeem( (int) $member->id, $gift_id );

        if ( $result['success'] ) {
            do_action( 'epc_points_changed', (int) $member->id );
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }
}
