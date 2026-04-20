<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EPC_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menus() {
        add_menu_page(
            __( 'Pappou Club', 'epappous-club' ),
            __( 'Pappou Club', 'epappous-club' ),
            'manage_options',
            'epc-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-groups',
            30
        );

        add_submenu_page(
            'epc-dashboard',
            __( 'Μέλη', 'epappous-club' ),
            __( 'Μέλη', 'epappous-club' ),
            'manage_options',
            'epc-members',
            [ $this, 'render_members' ]
        );

        add_submenu_page(
            'epc-dashboard',
            __( 'Ρυθμίσεις', 'epappous-club' ),
            __( 'Ρυθμίσεις', 'epappous-club' ),
            'manage_options',
            'epc-settings',
            [ $this, 'render_settings' ]
        );

        add_submenu_page(
            'epc-dashboard',
            __( 'Referrals', 'epappous-club' ),
            __( 'Referrals', 'epappous-club' ),
            'manage_options',
            'epc-referrals',
            [ $this, 'render_referrals' ]
        );

        add_submenu_page(
            'epc-dashboard',
            __( 'Ιστορικό Πόντων', 'epappous-club' ),
            __( 'Ιστορικό Πόντων', 'epappous-club' ),
            'manage_options',
            'epc-points-log',
            [ $this, 'render_points_log' ]
        );
    }

    public function enqueue_assets( $hook ) {
        $is_epc_page    = strpos( $hook, 'epc-' ) !== false;
        $is_profile     = in_array( $hook, [ 'profile.php', 'user-edit.php' ], true );

        if ( ! $is_epc_page && ! $is_profile ) {
            return;
        }

        // Page-specific extras only where actually used. Loading wp-color-picker
        // and wp.media on every epc-* / user-edit page slows wp-admin a lot.
        $needs_color_picker = ( 'epc-dashboard_page_epc-settings' === $hook )
            || ( 'pappou-club_page_epc-settings' === $hook )
            || ( 'toplevel_page_epc-settings' === $hook )
            || ( false !== strpos( (string) $hook, 'epc-settings' ) );
        $needs_debug_i18n   = ( false !== strpos( (string) $hook, 'epc-points-log' ) );

        $script_deps = [ 'jquery', 'wp-i18n' ];
        if ( $needs_color_picker ) {
            $script_deps[] = 'wp-color-picker';
        }

        wp_enqueue_style(
            'epc-admin-css',
            EPC_PLUGIN_URL . 'admin/css/admin.css',
            [],
            EPC_VERSION
        );

        wp_enqueue_script(
            'epc-admin-js',
            EPC_PLUGIN_URL . 'admin/js/admin.js',
            $script_deps,
            EPC_VERSION,
            true
        );

        // Allow language packs (.json) loaded via wp i18n make-json to translate
        // any wp.i18n.__() calls inside admin.js.
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                'epc-admin-js',
                'epappous-club',
                EPC_PLUGIN_DIR . 'languages'
            );
        }

        if ( $needs_color_picker ) {
            wp_enqueue_style( 'wp-color-picker' );
        }

        $i18n = [
            'confirmDeleteNote' => __( 'Διαγραφή σημείωσης;', 'epappous-club' ),
            'saved'            => __( 'Αποθηκεύτηκε!', 'epappous-club' ),
            'error'            => __( 'Σφάλμα!', 'epappous-club' ),
            'editNote'         => __( 'Επεξεργασία', 'epappous-club' ),
            'deleteNote'       => __( 'Διαγραφή', 'epappous-club' ),
            'saveNote'         => __( 'Αποθήκευση', 'epappous-club' ),
            'cancelNote'       => __( 'Ακύρωση', 'epappous-club' ),
            'noteEditedPrefix' => __( 'επεξ.', 'epappous-club' ),
            'placeholderTierLabel'  => __( 'Ετικέτα', 'epappous-club' ),
            'placeholderTierMin'    => __( 'Ελ. πόντοι', 'epappous-club' ),
            'placeholderTierMult'   => __( 'Πολ/στής', 'epappous-club' ),
            'placeholderTierColor'  => __( 'Χρώμα', 'epappous-club' ),
            'remove'                => __( 'Αφαίρεση', 'epappous-club' ),
            'membershipActive'      => __( 'Ενεργό', 'epappous-club' ),
            'membershipInactive'    => __( 'Ανενεργό', 'epappous-club' ),
            'membershipActiveBadge'   => __( 'Ενεργό Μέλος', 'epappous-club' ),
            'membershipInactiveBadge' => __( 'Inactive', 'epappous-club' ),
            'enrolling'             => __( 'Εγγραφή...', 'epappous-club' ),
            'enrollInClub'          => __( 'Εγγραφή στο Club', 'epappous-club' ),
            'fillPoints'            => __( 'Συμπλήρωσε πόντους', 'epappous-club' ),
            'pointsWord'            => __( 'πόντοι', 'epappous-club' ),
            'noMembersFound'        => __( 'Δεν βρέθηκαν μέλη', 'epappous-club' ),
            'noProductsFound'       => __( 'Δεν βρέθηκαν προϊόντα', 'epappous-club' ),
            'pickMember'            => __( 'Επίλεξε μέλος', 'epappous-club' ),
            'savingEllipsis'        => __( 'Αποθήκευση...', 'epappous-club' ),
            'apply'                 => __( 'Εφαρμογή', 'epappous-club' ),
            'confirmDeleteRule'     => __( 'Διαγραφή κανόνα;', 'epappous-club' ),
            'genericError'          => __( 'Σφάλμα', 'epappous-club' ),
            'cassetteEmailSending'  => __( 'Αποστολή...', 'epappous-club' ),
            'cassetteEmailButton'   => __( 'Ενημέρωση για κασσετίνα', 'epappous-club' ),
        ];

        // Heavy debug payload (~5KB of long translatable sentences) only on
        // the points-log page where the explanation modal is actually used.
        if ( $needs_debug_i18n ) {
            $i18n['debug'] = [
                'sectionMember'  => __( 'Στοιχεία Μέλους', 'epappous-club' ),
                'sectionLog'     => __( 'Στοιχεία Εγγραφής', 'epappous-club' ),
                'memberId'       => __( 'ID Μέλους:', 'epappous-club' ),
                'name'           => __( 'Όνομα:', 'epappous-club' ),
                'email'          => __( 'Email:', 'epappous-club' ),
                'currentPoints'  => __( 'Τρέχοντες Πόντοι:', 'epappous-club' ),
                'referralCode'   => __( 'Referral Code:', 'epappous-club' ),
                'logId'          => __( 'Log ID:', 'epappous-club' ),
                'points'         => __( 'Πόντοι:', 'epappous-club' ),
                'reasonKey'      => __( 'Λόγος (key):', 'epappous-club' ),
                'reason'         => __( 'Λόγος:', 'epappous-club' ),
                'reference'      => __( 'Reference:', 'epappous-club' ),
                'date'           => __( 'Ημερομηνία:', 'epappous-club' ),
                'whyGiven'       => __( 'Γιατί δόθηκαν αυτοί οι πόντοι;', 'epappous-club' ),
                'pointsWord'     => __( 'πόντοι', 'epappous-club' ),
                'pointsWordAcc'  => __( 'πόντους', 'epappous-club' ),
                'unknownReason'  => __( 'Ο λόγος "{reason}" δεν αναγνωρίζεται ως γνωστός τύπος. Πόντοι: {points}. Reference: {reference_type} #{reference_id}.', 'epappous-club' ),
                'reasons' => [
                    'birthday_bonus'             => __( 'Το μέλος <strong>{member_name}</strong> είχε γενέθλια. Ένα ημερήσιο cron job ελέγχει ποια μέλη έχουν γενέθλια σήμερα (βάσει date_of_birth) και αποδίδει αυτόματα <strong>{points} πόντους</strong>. Αυτό γίνεται μία φορά ανά ημερολογιακό έτος (reference_id = {reference_id} = το έτος). Η τιμή ρυθμίζεται στο Ρυθμίσεις → Πόντοι → Μπόνους Γενεθλίων.', 'epappous-club' ),
                    'referral_bonus_referrer'    => __( 'Το μέλος <strong>{member_name}</strong> προσκάλεσε κάποιον (μέλος #{reference_id}) μέσω referral link και κέρδισε <strong>{points} πόντους</strong> ως ανταμοιβή. Η ανταμοιβή δόθηκε κατά την εγγραφή του νέου μέλους. Ρυθμίζεται στο Ρυθμίσεις → Referral → Ανταμοιβή Αυτού που Προσκαλεί.', 'epappous-club' ),
                    'referral_bonus_referred'    => __( 'Το μέλος <strong>{member_name}</strong> εγγράφηκε μέσω referral link από μέλος #{reference_id} και κέρδισε <strong>{points} πόντους</strong> ως μπόνους εγγραφής. Ρυθμίζεται στο Ρυθμίσεις → Referral → Ανταμοιβή Νέου Μέλους.', 'epappous-club' ),
                    'referral_purchase_referrer' => __( 'Ο referred φίλος ολοκλήρωσε αγορά (παραγγελία #{reference_id}). Ο referrer <strong>{member_name}</strong> κέρδισε <strong>{points} πόντους</strong>. Ρυθμίζεται στο Ρυθμίσεις → Referral (Track Purchase + Reward Referrer).', 'epappous-club' ),
                    'referral_purchase_referred' => __( 'Το μέλος <strong>{member_name}</strong> ολοκλήρωσε την πρώτη αγορά (παραγγελία #{reference_id}) αφού εγγράφηκε μέσω referral. Κέρδισε <strong>{points} πόντους</strong>. Ρυθμίζεται στο Ρυθμίσεις → Referral (Track Purchase + Reward Referred).', 'epappous-club' ),
                    'gift_redemption'            => __( 'Το μέλος <strong>{member_name}</strong> πέρασε στο WooCommerce καλάθι ένα προϊόν δώρου που αγοράζεται μόνο με πόντους (παραγγελία #{reference_id}). Όταν η παραγγελία πήγε σε processing/completed αφαιρέθηκαν <strong>{abs_points} πόντοι</strong>. Αν η παραγγελία ακυρωθεί ή γίνει refunded, οι πόντοι επιστρέφονται με reason gift_refund.', 'epappous-club' ),
                    'gift_refund'                => __( 'Επιστροφή πόντων από ακύρωση/refund παραγγελίας #{reference_id} που περιείχε προϊόντα δώρου. Δόθηκαν πίσω <strong>{points} πόντοι</strong> στο μέλος <strong>{member_name}</strong>.', 'epappous-club' ),
                    'order_earning'              => __( 'Το μέλος <strong>{member_name}</strong> πέρασε παραγγελία #{reference_id} σε processing/completed και κέρδισε <strong>{points} πόντους</strong> βάσει του ποσού αγοράς. Υπολογισμός: ποσό × πόντοι ανά €. Ρυθμίζεται στο Ρυθμίσεις → Πόντοι (Πόντοι ανά €).', 'epappous-club' ),
                    'order_reversal'             => __( 'Η παραγγελία #{reference_id} ακυρώθηκε ή έγινε refunded, οπότε αφαιρέθηκαν <strong>{abs_points} πόντοι</strong> από το μέλος <strong>{member_name}</strong>.', 'epappous-club' ),
                    'manual_adjustment'          => __( 'Χειροκίνητη προσαρμογή πόντων από διαχειριστή. <strong>{signed_points} πόντοι</strong> στο μέλος <strong>{member_name}</strong>.', 'epappous-club' ),
                    'points_expiry'              => __( 'Αυτόματη λήξη πόντων. <strong>{abs_points} πόντοι</strong> έληξαν βάσει της ρύθμισης Λήξη Πόντων ({reference_id} ημέρες). Ρυθμίζεται στο Ρυθμίσεις → Πόντοι → Λήξη Πόντων.', 'epappous-club' ),
                    'signup_bonus'               => __( 'Μπόνους εγγραφής. Το μέλος <strong>{member_name}</strong> κέρδισε <strong>{points} πόντους</strong> κατά την εγγραφή στο club.', 'epappous-club' ),
                    'checkout_redemption'        => __( 'Το μέλος <strong>{member_name}</strong> χρησιμοποίησε <strong>{abs_points} πόντους</strong> ως έκπτωση στην παραγγελία #{reference_id}. Η αξία μετατράπηκε σε € βάσει της ρύθμισης Αξία Πόντου (epc_points_value_euro). Μέγιστο ποσοστό έκπτωσης: epc_max_redeem_percent. Ελάχιστοι πόντοι: epc_min_redeem_points.', 'epappous-club' ),
                ],
            ];
        }

        wp_localize_script( 'epc-admin-js', 'epcAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'epc_admin_nonce' ),
            'i18n'    => $i18n,
        ] );
    }

    public function render_dashboard() {
        include EPC_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    public function render_members() {
        include EPC_PLUGIN_DIR . 'templates/admin-members.php';
    }

    public function render_settings() {
        include EPC_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function render_referrals() {
        include EPC_PLUGIN_DIR . 'templates/admin-referrals.php';
    }

    public function render_points_log() {
        include EPC_PLUGIN_DIR . 'templates/admin-points-log.php';
    }
}
