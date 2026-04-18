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

        add_action( 'plugins_loaded', [ $this, 'register_woocommerce_my_account' ], 20 );
    }

    /**
     * After WooCommerce is loaded: endpoint + My Account menu (club dashboard).
     */
    public function register_woocommerce_my_account() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        add_action( 'init', [ $this, 'register_wc_account_endpoint' ], 20 );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'wc_account_menu_items' ], 40 );
        add_action( 'woocommerce_account_pappou-club_endpoint', [ $this, 'wc_account_endpoint_content' ] );
        add_action( 'admin_init', [ $this, 'maybe_flush_rewrite_for_wc_endpoint' ] );
    }

    /**
     * One-time flush after new WooCommerce endpoint (My Account → club tab).
     */
    public function maybe_flush_rewrite_for_wc_endpoint() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        if ( get_option( 'epc_wc_pappou_endpoint_flushed', '0' ) === '1' ) {
            return;
        }
        flush_rewrite_rules( false );
        update_option( 'epc_wc_pappou_endpoint_flushed', '1' );
    }

    /**
     * WooCommerce My Account → tab for club dashboard (shortcode content).
     */
    public function register_wc_account_endpoint() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        add_rewrite_endpoint( 'pappou-club', EP_PAGES );
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public function wc_account_menu_items( $items ) {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return $items;
        }
        $label = EPC_Settings::get( 'epc_club_name' );
        if ( ! is_string( $label ) || $label === '' ) {
            $label = __( 'Pappou Club', 'epappous-club' );
        }
        $new = [ 'pappou-club' => $label ];
        $logout = isset( $items['customer-logout'] ) ? [ 'customer-logout' => $items['customer-logout'] ] : [];
        unset( $items['customer-logout'] );
        return array_merge( $items, $new, $logout );
    }

    public function wc_account_endpoint_content() {
        echo do_shortcode( '[epappous_profile]' );
    }

    public function enqueue_assets() {
        global $post;

        $wc_club_tab = function_exists( 'is_account_page' ) && is_account_page()
            && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'pappou-club' );

        $has_shortcode = $post && (
            has_shortcode( $post->post_content, 'epappous_register' )
            || has_shortcode( $post->post_content, 'epappous_profile' )
            || has_shortcode( $post->post_content, 'epappous_gifts' )
        );

        if ( ! $has_shortcode && ! $wc_club_tab ) {
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

        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return '<p>' . esc_html__( 'Το club δεν είναι ενεργό αυτή τη στιγμή.', 'epappous-club' ) . '</p>';
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
        $currency_lbl = EPC_Settings::get( 'epc_currency_label' );
        $club_lbl = EPC_Settings::get( 'epc_club_name' );

        $ref_enabled   = EPC_Settings::get( 'epc_referral_enabled' ) === '1';
        $track_mem     = EPC_Settings::get( 'epc_referral_track_membership' ) === '1';
        $track_purchase = EPC_Settings::get( 'epc_referral_track_purchase' ) === '1';
        $reward_ref    = (int) EPC_Settings::get( 'epc_referral_reward_referrer' );
        $reward_new    = (int) EPC_Settings::get( 'epc_referral_reward_referred' );
        $req_purchase  = EPC_Settings::get( 'epc_referral_require_purchase' ) === '1';
        $min_order     = (float) EPC_Settings::get( 'epc_referral_min_order' );

        $show_referral_ui = $ref_enabled && ( $track_mem || $track_purchase );
        $ref_stats       = $show_referral_ui ? EPC_Referral::get_stats( (int) $member['id'] ) : null;
        $share_link      = $show_referral_ui ? add_query_arg( 'ref', rawurlencode( $member['referral_code'] ), home_url( '/' ) ) : '';

        ob_start();
        ?>
        <div class="epc-profile-wrap">
            <div class="epc-profile-card">
                <h2><?php printf( esc_html__( 'Γεια σου, %s!', 'epappous-club' ), esc_html( $member['first_name'] ) ); ?></h2>
                <p class="epc-profile-lead"><?php echo esc_html( sprintf( __( 'Το dashboard του %s — οι πόντοι και τα προνόμιά σου.', 'epappous-club' ), $club_lbl ) ); ?></p>

                <div class="epc-profile-guidance">
                    <h3 class="epc-profile-guidance-title"><?php esc_html_e( 'Πώς λειτουργεί;', 'epappous-club' ); ?></h3>
                    <ol class="epc-profile-guidance-list">
                        <li>
                            <?php
                            printf(
                                /* translators: %s: label for points (e.g. "Πόντοι") */
                                esc_html__( 'Βλέπεις το ισοζύγιό σου σε %s — κερδίζεις πόντους από αγορές στο κατάστημα (όταν ολοκληρώνεται η παραγγελία σου), σύμφωνα με τις ρυθμίσεις του club.', 'epappous-club' ),
                                esc_html( $currency_lbl )
                            );
                            ?>
                        </li>
                        <li><?php esc_html_e( 'Μπορείς να εξαργυρώσεις πόντους στο checkout (έκπτωση) ή να επιλέξεις δώρα από τον κατάλογο δώρων του club, αν είναι διαθέσιμα.', 'epappous-club' ); ?></li>
                        <?php /* Tiers disabled
                        <li><?php esc_html_e( 'Η βαθμίδα (tier) μπορεί να επηρεάζει τον πολλαπλασιαστή κερδών — όπως έχει οριστεί από το κατάστημα.', 'epappous-club' ); ?></li>
                        */ ?>
                        <?php if ( $show_referral_ui ) : ?>
                            <li>
                                <?php esc_html_e( 'Referral: μοιράζεσαι το προσωπικό σου link. Όταν κάποιος επισκέπτεται το site με αυτό το link, αποθηκεύεται για λίγες μέρες. Αν εγγραφεί ως μέλος ή/και αγοράσει, μπορείτε να κερδίσετε πόντους — ανάλογα με τις ρυθμίσεις που βλέπεις παρακάτω.', 'epappous-club' ); ?>
                            </li>
                        <?php endif; ?>
                    </ol>
                </div>

                <div class="epc-profile-stats <?php echo $show_referral_ui ? 'epc-profile-stats--with-ref' : 'epc-profile-stats--no-ref'; ?>">
                    <div class="epc-profile-stat">
                        <span class="epc-profile-stat-value"><?php echo esc_html( $currency . ' ' . number_format( (int) $member['points'] ) ); ?></span>
                        <span class="epc-profile-stat-label"><?php echo esc_html( $currency_lbl ); ?></span>
                    </div>
                    <?php /*
                    <div class="epc-profile-stat">
                        <span class="epc-profile-stat-value"><?php echo esc_html( ucfirst( $member['tier'] ) ); ?></span>
                        <span class="epc-profile-stat-label"><?php esc_html_e( 'Βαθμίδα', 'epappous-club' ); ?></span>
                    </div>
                    */ ?>
                    <?php if ( $show_referral_ui ) : ?>
                    <div class="epc-profile-stat epc-profile-stat--referral">
                        <span class="epc-profile-stat-value"><?php echo esc_html( $member['referral_code'] ); ?></span>
                        <span class="epc-profile-stat-label"><?php esc_html_e( 'Κωδικός Referral', 'epappous-club' ); ?></span>
                        <?php if ( $ref_stats ) : ?>
                            <span class="epc-profile-stat-sub">
                                <?php
                                printf(
                                    /* translators: 1: completed count, 2: label for points */
                                    esc_html__( '%1$s ολοκληρωμένα · %2$s πόντοι από referrals', 'epappous-club' ),
                                    (int) $ref_stats['completed'],
                                    number_format_i18n( (int) $ref_stats['points_earned'] )
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ( $show_referral_ui && $share_link !== '' ) : ?>
                <div class="epc-profile-referral-box">
                    <h3 class="epc-profile-referral-title"><?php esc_html_e( 'Το link σου για referrals', 'epappous-club' ); ?></h3>
                    <p class="epc-profile-referral-hint"><?php esc_html_e( 'Αντέγραψε και στείλε το στους φίλους σου. Όταν μπουν από αυτό το link και γίνουν μέλη ή αγοράσουν, μπορείτε να κερδίσετε πόντους (αν ισχύουν οι κανόνες του καταστήματος).', 'epappous-club' ); ?></p>
                    <div class="epc-profile-share-row">
                        <input type="text" readonly class="epc-profile-share-input" id="epc-ref-share-url" value="<?php echo esc_attr( $share_link ); ?>" aria-label="<?php esc_attr_e( 'Referral link', 'epappous-club' ); ?>" />
                        <button type="button" class="epc-btn-secondary epc-copy-ref-link" data-copy="<?php echo esc_attr( $share_link ); ?>"><?php esc_html_e( 'Αντιγραφή', 'epappous-club' ); ?></button>
                    </div>
                    <div class="epc-profile-reward-grid">
                        <?php if ( $track_mem ) : ?>
                            <div class="epc-profile-reward-item">
                                <strong><?php esc_html_e( 'Εσύ (πρόσκληση)', 'epappous-club' ); ?></strong>
                                <span><?php printf( esc_html__( 'Έως %s πόντοι όταν ο φίλος γίνεται μέλος', 'epappous-club' ), number_format_i18n( $reward_ref ) ); ?></span>
                                <?php if ( $req_purchase ) : ?>
                                    <em class="epc-profile-reward-note"><?php esc_html_e( 'Η ανταμοιβή καταβάλλεται μετά την πρώτη αγορά του φίλου — όπως έχει οριστεί στο κατάστημα.', 'epappous-club' ); ?></em>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $track_mem ) : ?>
                            <div class="epc-profile-reward-item">
                                <strong><?php esc_html_e( 'Ο φίλος (νέο μέλος)', 'epappous-club' ); ?></strong>
                                <span><?php printf( esc_html__( 'Έως %s πόντοι εγγραφής', 'epappous-club' ), number_format_i18n( $reward_new ) ); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ( $track_purchase ) : ?>
                            <div class="epc-profile-reward-item epc-profile-reward-item--wide">
                                <strong><?php esc_html_e( 'Αγορά μέσω referral', 'epappous-club' ); ?></strong>
                                <span>
                                    <?php
                                    printf(
                                        esc_html__( 'Επιπλέον πόντοι όταν ολοκληρωθεί επιλέξιμη παραγγελία — εσύ και ο φίλος (ωφελήματα όπως στο admin).', 'epappous-club' )
                                    );
                                    if ( $min_order > 0 ) {
                                        echo ' ';
                                        if ( function_exists( 'wc_price' ) ) {
                                            echo wp_kses_post( wc_price( $min_order ) );
                                        } else {
                                            printf( esc_html__( 'Ελάχιστο ποσό παραγγελίας: %s', 'epappous-club' ), number_format_i18n( $min_order, 2 ) );
                                        }
                                    }
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

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
