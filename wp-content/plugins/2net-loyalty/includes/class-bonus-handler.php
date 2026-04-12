<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bonus Handler — registration bonus, welcome coupon,
 * referral system, birthday bonus.
 */
class TwoNet_Bonus_Handler {

    private static $instance = null;

    const REFERRAL_META          = '_2net_loyalty_referral_code';
    const REFERRED_BY_META       = '_2net_loyalty_referred_by';
    const WELCOME_COUPON_META    = '_2net_loyalty_welcome_coupon_sent';
    const BIRTHDAY_AWARD_META   = '_2net_last_birthday_award';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! TwoNet_Loyalty_Core::is_enabled() ) {
            return;
        }

        // Registration bonus + welcome coupon + referral code generation.
        add_action( 'user_register', [ $this, 'on_user_register' ], 10, 1 );

        // Referral: award points to referrer on first completed order.
        add_action( 'woocommerce_order_status_completed', [ $this, 'award_referral_points' ], 20, 1 );

        // Referral: registration form field.
        add_action( 'woocommerce_register_form', [ $this, 'render_referral_field' ] );
        add_action( 'woocommerce_created_customer', [ $this, 'save_referral_on_register' ], 10, 1 );

        // Shortcode for referral link.
        add_shortcode( '2net_referral_link', [ $this, 'referral_link_shortcode' ] );
    }

    /* ------------------------------------------------------------------
     * Registration bonus + welcome coupon
     * ----------------------------------------------------------------*/

    public function on_user_register( $user_id ) {
        // Generate unique referral code.
        $code = $this->generate_referral_code( $user_id );
        update_user_meta( $user_id, self::REFERRAL_META, $code );

        // Registration bonus points.
        $bonus = (int) TwoNet_Loyalty_Core::get_setting( 'registration_bonus', 500 );
        if ( $bonus > 0 ) {
            TwoNet_Points_Manager::add_points(
                $user_id,
                $bonus,
                'registration',
                0,
                sprintf( __( 'Δώρο εγγραφής: %d πόντοι', '2net-loyalty' ), $bonus )
            );
        }

        // Welcome coupon (20% off first order).
        $this->send_welcome_coupon( $user_id );
    }

    /**
     * Create and email a WELCOME coupon for 20% off the first order.
     */
    private function send_welcome_coupon( int $user_id ) {
        if ( get_user_meta( $user_id, self::WELCOME_COUPON_META, true ) ) {
            return;
        }

        $user    = get_userdata( $user_id );
        $percent = (int) TwoNet_Loyalty_Core::get_setting( 'welcome_coupon_percent', 20 );

        $code = 'welcome-' . $user_id . '-' . wp_generate_password( 4, false );

        $coupon = new \WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( 'percent' );
        $coupon->set_amount( $percent );
        $coupon->set_usage_limit( 1 );
        $coupon->set_usage_limit_per_user( 1 );
        $coupon->set_individual_use( true );
        $coupon->set_email_restrictions( [ $user->user_email ] );
        $coupon->save();

        update_user_meta( $user_id, self::WELCOME_COUPON_META, $code );

        wp_mail(
            $user->user_email,
            __( 'Καλώς ήρθατε! Κουπόνι 20% στην πρώτη παραγγελία', '2net-loyalty' ),
            sprintf(
                __( "Γεια σας %s,\n\nΚαλώς ήρθατε στην οικογένειά μας!\n\nΧρησιμοποιήστε τον κωδικό %s για %d%% έκπτωση στην πρώτη σας παραγγελία.\n\nΕπίσης, σας πιστώθηκαν %d πόντοι loyalty ως δώρο εγγραφής!\n\nΕυχαριστούμε,\n2NET", '2net-loyalty' ),
                $user->display_name,
                $code,
                $percent,
                (int) TwoNet_Loyalty_Core::get_setting( 'registration_bonus', 500 )
            )
        );
    }

    /* ------------------------------------------------------------------
     * Referral system
     * ----------------------------------------------------------------*/

    /**
     * Generate a unique referral code for a user.
     */
    private function generate_referral_code( int $user_id ): string {
        $user = get_userdata( $user_id );
        $base = sanitize_user( $user->user_login, true );
        $base = substr( strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $base ) ), 0, 8 );

        if ( strlen( $base ) < 3 ) {
            $base = 'user';
        }

        return $base . $user_id;
    }

    /**
     * Find user by referral code.
     */
    public static function get_user_by_referral_code( string $code ): ?int {
        $users = get_users( [
            'meta_key'   => self::REFERRAL_META,
            'meta_value' => sanitize_text_field( $code ),
            'number'     => 1,
            'fields'     => 'ids',
        ] );

        return ! empty( $users ) ? (int) $users[0] : null;
    }

    /**
     * Render referral code field on WooCommerce registration form.
     */
    public function render_referral_field() {
        $ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="referral_code"><?php esc_html_e( 'Κωδικός σύστασης (προαιρετικό)', '2net-loyalty' ); ?></label>
            <input type="text" class="woocommerce-Input" name="referral_code" id="referral_code"
                   value="<?php echo esc_attr( $ref ); ?>" />
        </p>
        <?php
    }

    /**
     * Save referral association on registration.
     */
    public function save_referral_on_register( $user_id ) {
        if ( empty( $_POST['referral_code'] ) ) {
            return;
        }

        $code        = sanitize_text_field( wp_unslash( $_POST['referral_code'] ) );
        $referrer_id = self::get_user_by_referral_code( $code );

        // Prevent self-referral.
        if ( ! $referrer_id || $referrer_id === $user_id ) {
            return;
        }

        update_user_meta( $user_id, self::REFERRED_BY_META, $referrer_id );
    }

    /**
     * Award referral bonus to the referrer when the referred user's first order completes.
     */
    public function award_referral_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) {
            return;
        }

        $referrer_id = get_user_meta( $user_id, self::REFERRED_BY_META, true );
        if ( ! $referrer_id ) {
            return;
        }

        // Only award on first completed order.
        $order_count = wc_get_customer_order_count( $user_id );
        if ( $order_count > 1 ) {
            return;
        }

        // Idempotency check via ledger.
        if ( TwoNet_Points_Manager::has_transaction( (int) $referrer_id, 'referral', $user_id ) ) {
            return;
        }

        $bonus = (int) TwoNet_Loyalty_Core::get_setting( 'referral_bonus', 400 );
        if ( $bonus > 0 ) {
            $referred_user = get_userdata( $user_id );
            TwoNet_Points_Manager::add_points(
                (int) $referrer_id,
                $bonus,
                'referral',
                $user_id,
                sprintf(
                    __( 'Πόντοι σύστασης — ο χρήστης %s ολοκλήρωσε παραγγελία', '2net-loyalty' ),
                    $referred_user->display_name
                )
            );
        }
    }

    /**
     * Referral link shortcode.
     */
    public function referral_link_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id = get_current_user_id();
        $code    = get_user_meta( $user_id, self::REFERRAL_META, true );

        if ( ! $code ) {
            return '';
        }

        $url = add_query_arg( 'ref', $code, wc_get_page_permalink( 'myaccount' ) );
        return sprintf(
            '<span class="twonet-referral-link">%s <code>%s</code></span>',
            esc_url( $url ),
            esc_html( $code )
        );
    }

    /* ------------------------------------------------------------------
     * Birthday bonus (called from CLI script or WP-Cron)
     * ----------------------------------------------------------------*/

    /**
     * Award birthday bonus to a user. Idempotent — checks year.
     *
     * @param int $user_id
     * @return bool True if awarded, false if already awarded or error.
     */
    public static function award_birthday_bonus( int $user_id ): bool {
        $current_year = (int) gmdate( 'Y' );

        // Idempotency: check if birthday bonus was already given this year.
        $last_award = get_user_meta( $user_id, self::BIRTHDAY_AWARD_META, true );
        if ( (int) $last_award === $current_year ) {
            return false;
        }

        $bonus = (int) TwoNet_Loyalty_Core::get_setting( 'birthday_bonus', 1500 );
        if ( $bonus <= 0 ) {
            return false;
        }

        $result = TwoNet_Points_Manager::add_points(
            $user_id,
            $bonus,
            'birthday',
            $current_year,
            sprintf( __( 'Χρόνια πολλά! Δώρο γενεθλίων %d πόντοι (%d)', '2net-loyalty' ), $bonus, $current_year )
        );

        if ( false === $result ) {
            return false;
        }

        update_user_meta( $user_id, self::BIRTHDAY_AWARD_META, $current_year );

        // Send birthday email.
        $user = get_userdata( $user_id );
        if ( $user ) {
            wp_mail(
                $user->user_email,
                __( 'Χρόνια Πολλά! 🎂', '2net-loyalty' ),
                sprintf(
                    __( "Αγαπητέ/ή %s,\n\nΧρόνια πολλά! Σας πιστώσαμε %d πόντους loyalty ως δώρο γενεθλίων.\n\nΕυχαριστούμε που είστε μέλος μας!\n\n2NET", '2net-loyalty' ),
                    $user->display_name,
                    $bonus
                )
            );
        }

        return true;
    }

    /**
     * Find users with birthday today and award bonus.
     * Used by CLI script and/or WP-Cron.
     *
     * Birthday is expected in user meta '_birthday' or 'billing_birthday'
     * in format 'YYYY-MM-DD', 'MM-DD', 'DD/MM/YYYY', or 'DD/MM'.
     *
     * @return array [ 'awarded' => [...], 'skipped' => [...] ]
     */
    public static function process_birthdays_today(): array {
        $today_md = gmdate( 'm-d' );
        $results  = [ 'awarded' => [], 'skipped' => [] ];

        $users = get_users( [ 'fields' => 'ids' ] );

        foreach ( $users as $user_id ) {
            $birthday = self::get_user_birthday( (int) $user_id );
            if ( ! $birthday ) {
                continue;
            }

            if ( $birthday !== $today_md ) {
                continue;
            }

            $awarded = self::award_birthday_bonus( (int) $user_id );
            if ( $awarded ) {
                $results['awarded'][] = $user_id;
            } else {
                $results['skipped'][] = $user_id;
            }
        }

        return $results;
    }

    /**
     * Normalize birthday meta to MM-DD format.
     */
    private static function get_user_birthday( int $user_id ): ?string {
        $raw = get_user_meta( $user_id, '_birthday', true );
        if ( ! $raw ) {
            $raw = get_user_meta( $user_id, 'billing_birthday', true );
        }
        if ( ! $raw ) {
            $raw = get_user_meta( $user_id, 'birthday', true );
        }
        if ( ! $raw ) {
            return null;
        }

        $raw = trim( $raw );

        // YYYY-MM-DD
        if ( preg_match( '/^\d{4}-(\d{2})-(\d{2})$/', $raw, $m ) ) {
            return $m[1] . '-' . $m[2];
        }

        // MM-DD
        if ( preg_match( '/^(\d{2})-(\d{2})$/', $raw ) ) {
            return $raw;
        }

        // DD/MM/YYYY or DD/MM
        if ( preg_match( '#^(\d{1,2})/(\d{1,2})(?:/\d{2,4})?$#', $raw, $m ) ) {
            return str_pad( $m[2], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $m[1], 2, '0', STR_PAD_LEFT );
        }

        return null;
    }
}
