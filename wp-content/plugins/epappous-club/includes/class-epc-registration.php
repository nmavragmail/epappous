<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Member Registration
 *
 * Provides:
 * - Shortcode [epappous_register] for the front-end registration form
 * - Form processing & validation
 * - Birthday (date_of_birth) collection at sign-up
 * - Auto-generation of referral code
 * - Referral cookie integration
 */
class EPC_Registration {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'epappous_register', [ $this, 'render_form' ] );
        add_shortcode( 'epappous_profile', [ $this, 'render_profile' ] );
        add_action( 'wp_ajax_nopriv_epc_register_member', [ $this, 'process_registration' ] );
        add_action( 'wp_ajax_epc_register_member', [ $this, 'process_registration' ] );
        add_action( 'wp_ajax_epc_update_profile', [ $this, 'process_profile_update' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets() {
        global $post;
        if ( ! $post ) {
            return;
        }

        $has_shortcode = has_shortcode( $post->post_content, 'epappous_register' )
                      || has_shortcode( $post->post_content, 'epappous_profile' )
                      || has_shortcode( $post->post_content, 'epappous_gifts' );

        if ( ! $has_shortcode ) {
            return;
        }

        wp_enqueue_style(
            'epc-front-css',
            EPC_PLUGIN_URL . 'admin/css/front.css',
            [],
            EPC_VERSION
        );

        wp_enqueue_script(
            'epc-front-js',
            EPC_PLUGIN_URL . 'admin/js/front.js',
            [ 'jquery' ],
            EPC_VERSION,
            true
        );

        wp_localize_script( 'epc-front-js', 'epcFront', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'epc_front_nonce' ),
        ] );
    }

    /**
     * Render registration form via [epappous_register].
     */
    public function render_form( $atts ) {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return '<p>' . esc_html__( 'Το club δεν είναι ενεργό αυτή τη στιγμή.', 'epappous-club' ) . '</p>';
        }

        $club_name = esc_html( EPC_Settings::get( 'epc_club_name' ) );

        ob_start();
        ?>
        <div class="epc-register-wrap">
            <div class="epc-register-card">
                <h2><?php printf( esc_html__( 'Γίνε μέλος στο %s', 'epappous-club' ), $club_name ); ?></h2>
                <p class="epc-register-subtitle"><?php esc_html_e( 'Συμπλήρωσε τα στοιχεία σου για να γραφτείς.', 'epappous-club' ); ?></p>

                <div id="epc-register-messages"></div>

                <form id="epc-register-form" method="post">
                    <input type="hidden" name="action" value="epc_register_member" />
                    <?php wp_nonce_field( 'epc_front_nonce', 'nonce' ); ?>

                    <div class="epc-form-row">
                        <label for="epc-reg-first"><?php esc_html_e( 'Όνομα', 'epappous-club' ); ?> *</label>
                        <input type="text" id="epc-reg-first" name="first_name" required />
                    </div>

                    <div class="epc-form-row">
                        <label for="epc-reg-last"><?php esc_html_e( 'Επώνυμο', 'epappous-club' ); ?> *</label>
                        <input type="text" id="epc-reg-last" name="last_name" required />
                    </div>

                    <div class="epc-form-row">
                        <label for="epc-reg-email"><?php esc_html_e( 'Email', 'epappous-club' ); ?> *</label>
                        <input type="email" id="epc-reg-email" name="email" required />
                    </div>

                    <div class="epc-form-row">
                        <label for="epc-reg-phone"><?php esc_html_e( 'Τηλέφωνο', 'epappous-club' ); ?></label>
                        <input type="tel" id="epc-reg-phone" name="phone" />
                    </div>

                    <div class="epc-form-row">
                        <label for="epc-reg-dob"><?php esc_html_e( 'Ημερομηνία Γέννησης', 'epappous-club' ); ?></label>
                        <input type="date" id="epc-reg-dob" name="date_of_birth" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
                        <span class="epc-form-help"><?php esc_html_e( 'Θα λαμβάνεις πόντους δώρο στα γενέθλιά σου!', 'epappous-club' ); ?></span>
                    </div>

                    <?php
                    $terms_id   = EPC_Settings::get( 'epc_terms_page_id' );
                    $privacy_id = EPC_Settings::get( 'epc_privacy_page_id' );
                    if ( $terms_id || $privacy_id ) :
                    ?>
                        <div class="epc-form-row epc-form-checkbox">
                            <label>
                                <input type="checkbox" name="accept_terms" required />
                                <?php
                                if ( $terms_id && $privacy_id ) {
                                    printf(
                                        esc_html__( 'Αποδέχομαι τους %1$sΌρους Χρήσης%2$s και την %3$sΠολιτική Απορρήτου%4$s', 'epappous-club' ),
                                        '<a href="' . esc_url( get_permalink( $terms_id ) ) . '" target="_blank">', '</a>',
                                        '<a href="' . esc_url( get_permalink( $privacy_id ) ) . '" target="_blank">', '</a>'
                                    );
                                } elseif ( $terms_id ) {
                                    printf(
                                        esc_html__( 'Αποδέχομαι τους %1$sΌρους Χρήσης%2$s', 'epappous-club' ),
                                        '<a href="' . esc_url( get_permalink( $terms_id ) ) . '" target="_blank">', '</a>'
                                    );
                                } else {
                                    printf(
                                        esc_html__( 'Αποδέχομαι την %1$sΠολιτική Απορρήτου%2$s', 'epappous-club' ),
                                        '<a href="' . esc_url( get_permalink( $privacy_id ) ) . '" target="_blank">', '</a>'
                                    );
                                }
                                ?>
                            </label>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="epc-btn-primary" id="epc-register-submit">
                        <?php esc_html_e( 'Εγγραφή', 'epappous-club' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render member profile (view/edit birthday) via [epappous_profile].
     */
    public function render_profile( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Πρέπει να συνδεθείς για να δεις το προφίλ σου.', 'epappous-club' ) . '</p>';
        }

        global $wpdb;
        $user  = wp_get_current_user();
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_members WHERE user_id = %d OR email = %s LIMIT 1",
                $user->ID,
                $user->user_email
            ),
            ARRAY_A
        );

        if ( ! $member ) {
            return '<p>' . esc_html__( 'Δεν είσαι μέλος του club ακόμα.', 'epappous-club' )
                 . ' <a href="#epc-register-form">' . esc_html__( 'Γράψου τώρα!', 'epappous-club' ) . '</a></p>';
        }

        $currency = esc_html( EPC_Settings::get( 'epc_currency_symbol' ) );

        ob_start();
        ?>
        <div class="epc-profile-wrap">
            <div class="epc-profile-card">
                <h2><?php printf( esc_html__( 'Γεια σου, %s!', 'epappous-club' ), esc_html( $member['first_name'] ) ); ?></h2>

                <div class="epc-profile-stats">
                    <div class="epc-profile-stat">
                        <span class="epc-profile-stat-value"><?php echo esc_html( $currency . ' ' . number_format( (int) $member['points'] ) ); ?></span>
                        <span class="epc-profile-stat-label"><?php echo esc_html( EPC_Settings::get( 'epc_currency_label' ) ); ?></span>
                    </div>
                    <div class="epc-profile-stat">
                        <span class="epc-profile-stat-value"><?php echo esc_html( ucfirst( $member['tier'] ) ); ?></span>
                        <span class="epc-profile-stat-label"><?php esc_html_e( 'Βαθμίδα', 'epappous-club' ); ?></span>
                    </div>
                    <div class="epc-profile-stat">
                        <span class="epc-profile-stat-value"><?php echo esc_html( $member['referral_code'] ); ?></span>
                        <span class="epc-profile-stat-label"><?php esc_html_e( 'Κωδικός Referral', 'epappous-club' ); ?></span>
                    </div>
                </div>

                <div id="epc-profile-messages"></div>

                <form id="epc-profile-form" method="post">
                    <input type="hidden" name="action" value="epc_update_profile" />
                    <?php wp_nonce_field( 'epc_front_nonce', 'nonce' ); ?>

                    <div class="epc-form-row">
                        <label for="epc-prof-phone"><?php esc_html_e( 'Τηλέφωνο', 'epappous-club' ); ?></label>
                        <input type="tel" id="epc-prof-phone" name="phone"
                               value="<?php echo esc_attr( $member['phone'] ); ?>" />
                    </div>

                    <div class="epc-form-row">
                        <label for="epc-prof-dob"><?php esc_html_e( 'Ημερομηνία Γέννησης', 'epappous-club' ); ?></label>
                        <input type="date" id="epc-prof-dob" name="date_of_birth"
                               value="<?php echo esc_attr( $member['date_of_birth'] ?? '' ); ?>"
                               max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
                        <span class="epc-form-help"><?php esc_html_e( 'Θα λαμβάνεις πόντους δώρο στα γενέθλιά σου!', 'epappous-club' ); ?></span>
                    </div>

                    <button type="submit" class="epc-btn-primary">
                        <?php esc_html_e( 'Ενημέρωση', 'epappous-club' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: process new member registration.
     */
    public function process_registration() {
        check_ajax_referer( 'epc_front_nonce', 'nonce' );

        $first = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last  = sanitize_text_field( $_POST['last_name'] ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        $dob   = sanitize_text_field( $_POST['date_of_birth'] ?? '' );

        if ( empty( $first ) || empty( $last ) || empty( $email ) ) {
            wp_send_json_error( __( 'Παρακαλώ συμπλήρωσε όλα τα υποχρεωτικά πεδία.', 'epappous-club' ) );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( __( 'Μη έγκυρη διεύθυνση email.', 'epappous-club' ) );
        }

        // Check terms acceptance
        $terms_id   = EPC_Settings::get( 'epc_terms_page_id' );
        $privacy_id = EPC_Settings::get( 'epc_privacy_page_id' );
        if ( ( $terms_id || $privacy_id ) && empty( $_POST['accept_terms'] ) ) {
            wp_send_json_error( __( 'Πρέπει να αποδεχτείς τους όρους χρήσης.', 'epappous-club' ) );
        }

        // Check minimum age
        $min_age = (int) EPC_Settings::get( 'epc_min_age' );
        if ( $min_age > 0 && ! empty( $dob ) ) {
            $birth = new DateTime( $dob );
            $now   = new DateTime();
            $age   = (int) $now->diff( $birth )->y;
            if ( $age < $min_age ) {
                wp_send_json_error(
                    sprintf( __( 'Πρέπει να είσαι τουλάχιστον %d ετών.', 'epappous-club' ), $min_age )
                );
            }
        }

        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE email = %s",
                $email
            )
        );

        if ( $exists ) {
            wp_send_json_error( __( 'Αυτό το email είναι ήδη εγγεγραμμένο.', 'epappous-club' ) );
        }

        $referral_code = EPC_Referral::generate_code();
        $user_id       = is_user_logged_in() ? get_current_user_id() : null;

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_members",
            [
                'user_id'       => $user_id,
                'first_name'    => $first,
                'last_name'     => $last,
                'email'         => $email,
                'phone'         => $phone,
                'date_of_birth' => ! empty( $dob ) ? $dob : null,
                'referral_code' => $referral_code,
                'points'        => 0,
                'tier'          => 'basic',
                'status'        => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            wp_send_json_error( __( 'Σφάλμα κατά την εγγραφή. Δοκίμασε ξανά.', 'epappous-club' ) );
        }

        $member_id = (int) $wpdb->insert_id;

        do_action( 'epc_member_registered', $member_id, [
            'email'      => $email,
            'first_name' => $first,
            'last_name'  => $last,
        ] );

        EPC_Member_Sync::after_club_registration( $member_id, $email );

        wp_send_json_success( [
            'message'       => sprintf(
                __( 'Καλώς ήρθες στο %s! Ο κωδικός referral σου είναι: %s', 'epappous-club' ),
                EPC_Settings::get( 'epc_club_name' ),
                $referral_code
            ),
            'referral_code' => $referral_code,
        ] );
    }

    /**
     * AJAX: process profile update (birthday, phone).
     */
    public function process_profile_update() {
        check_ajax_referer( 'epc_front_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( 'Πρέπει να συνδεθείς.', 'epappous-club' ) );
        }

        global $wpdb;
        $user = wp_get_current_user();

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}epc_members WHERE user_id = %d OR email = %s LIMIT 1",
                $user->ID,
                $user->user_email
            )
        );

        if ( ! $member ) {
            wp_send_json_error( __( 'Δεν βρέθηκε μέλος.', 'epappous-club' ) );
        }

        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        $dob   = sanitize_text_field( $_POST['date_of_birth'] ?? '' );

        $update = [
            'phone' => $phone,
        ];
        $format = [ '%s' ];

        if ( ! empty( $dob ) ) {
            $update['date_of_birth'] = $dob;
            $format[] = '%s';
        }

        $wpdb->update(
            "{$wpdb->prefix}epc_members",
            $update,
            [ 'id' => $member->id ],
            $format,
            [ '%d' ]
        );

        wp_send_json_success( __( 'Το προφίλ ενημερώθηκε!', 'epappous-club' ) );
    }
}
