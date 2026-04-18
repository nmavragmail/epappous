<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Email Notifications
 *
 * Sends emails on key club events, each controlled by its own toggle in Settings.
 */
class EPC_Notifications {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'epc_member_registered', [ $this, 'on_new_member' ], 99, 2 );
        add_action( 'epc_referral_completed', [ $this, 'on_referral_completed' ], 10, 3 );
        add_action( 'epc_gift_redeemed', [ $this, 'on_gift_redeemed' ], 10, 3 );
        // add_action( 'epc_tier_upgraded', [ $this, 'on_tier_upgraded' ], 10, 3 );
    }

    private function admin_email(): string {
        $email = EPC_Settings::get( 'epc_admin_email' );
        return ! empty( $email ) ? $email : get_option( 'admin_email' );
    }

    private function club_name(): string {
        return EPC_Settings::get( 'epc_club_name' );
    }

    /**
     * New member registered.
     */
    public function on_new_member( int $member_id, array $data ) {
        if ( EPC_Settings::get( 'epc_notify_new_member' ) !== '1' ) {
            return;
        }

        $name = trim( ( $data['first_name'] ?? '' ) . ' ' . ( $data['last_name'] ?? '' ) );

        // Email to admin
        wp_mail(
            $this->admin_email(),
            sprintf(
                /* translators: 1: club name, 2: member full name */
                __( '[%1$s] Νέο μέλος: %2$s', 'epappous-club' ),
                $this->club_name(),
                $name
            ),
            sprintf(
                /* translators: 1: club name, 2: member name, 3: email, 4: member id */
                __( "Νέο μέλος εγγράφηκε στο %1\$s.\n\nΌνομα: %2\$s\nEmail: %3\$s\nID: %4\$d", 'epappous-club' ),
                $this->club_name(),
                $name,
                $data['email'] ?? '',
                $member_id
            )
        );

        // Email to member
        if ( ! empty( $data['email'] ) ) {
            wp_mail(
                $data['email'],
                sprintf(
                    /* translators: %s: club name */
                    __( 'Καλώς ήρθες στο %s!', 'epappous-club' ),
                    $this->club_name()
                ),
                sprintf(
                    /* translators: 1: first name, 2: club name */
                    __( "Γεια σου %1\$s!\n\nΗ εγγραφή σου στο %2\$s ολοκληρώθηκε.\nΚέρδισε πόντους και πάρε δώρα!\n\nΕυχαριστούμε!", 'epappous-club' ),
                    $data['first_name'] ?? '',
                    $this->club_name()
                )
            );
        }
    }

    /**
     * Referral completed.
     */
    public function on_referral_completed( int $referrer_id, int $referred_id, string $type ) {
        if ( EPC_Settings::get( 'epc_notify_referral_complete' ) !== '1' ) {
            return;
        }

        global $wpdb;
        $referrer = $wpdb->get_row(
            $wpdb->prepare( "SELECT first_name, email FROM {$wpdb->prefix}epc_members WHERE id = %d", $referrer_id )
        );

        if ( ! $referrer || empty( $referrer->email ) ) {
            return;
        }

        $type_label = $type === 'membership'
            ? __( 'εγγραφή μέλους', 'epappous-club' )
            : __( 'αγορά', 'epappous-club' );

        wp_mail(
            $referrer->email,
            sprintf(
                /* translators: %s: club name */
                __( '[%s] Referral ολοκληρώθηκε!', 'epappous-club' ),
                $this->club_name()
            ),
            sprintf(
                /* translators: 1: referrer first name, 2: referral type label (εγγραφή μέλους / αγορά) */
                __( "Γεια σου %1\$s!\n\nΤο referral σου (%2\$s) ολοκληρώθηκε και κέρδισες πόντους!\nΔες τους πόντους σου στο προφίλ σου.\n\nΕυχαριστούμε!", 'epappous-club' ),
                $referrer->first_name,
                $type_label
            )
        );
    }

    /**
     * Gift redeemed.
     */
    public function on_gift_redeemed( int $member_id, int $gift_id, int $redemption_id ) {
        if ( EPC_Settings::get( 'epc_notify_gift_redeemed' ) !== '1' ) {
            return;
        }

        global $wpdb;
        $member = $wpdb->get_row(
            $wpdb->prepare( "SELECT first_name, email FROM {$wpdb->prefix}epc_members WHERE id = %d", $member_id )
        );
        $gift = EPC_Gifts::get( $gift_id );

        // Admin notification
        wp_mail(
            $this->admin_email(),
            sprintf(
                /* translators: 1: club name, 2: redemption id */
                __( '[%1$s] Εξαργύρωση δώρου #%2$d', 'epappous-club' ),
                $this->club_name(),
                $redemption_id
            ),
            sprintf(
                /* translators: 1: member name or id, 2: member email, 3: gift title or id, 4: redemption id */
                __( "Το μέλος %1\$s (%2\$s) εξαργύρωσε το δώρο \"%3\$s\".\nRedemption ID: %4\$d", 'epappous-club' ),
                $member ? $member->first_name : '#' . $member_id,
                $member ? $member->email : '',
                $gift ? $gift['title'] : '#' . $gift_id,
                $redemption_id
            )
        );

        // Member confirmation
        if ( $member && ! empty( $member->email ) ) {
            wp_mail(
                $member->email,
                sprintf(
                    /* translators: %s: club name */
                    __( '[%s] Εξαργύρωση δώρου', 'epappous-club' ),
                    $this->club_name()
                ),
                sprintf(
                    /* translators: 1: member first name, 2: gift title or id */
                    __( "Γεια σου %1\$s!\n\nΕξαργύρωσες επιτυχώς το δώρο \"%2\$s\".\nΘα επικοινωνήσουμε μαζί σου σύντομα.\n\nΕυχαριστούμε!", 'epappous-club' ),
                    $member->first_name,
                    $gift ? $gift['title'] : '#' . $gift_id
                )
            );
        }
    }

    /**
     * Tier upgraded.
     * Disabled while site runs without tiers (hook above commented). Kept for re-enable.
     */
    /*
    public function on_tier_upgraded( int $member_id, string $old_tier, string $new_tier ) {
        if ( EPC_Settings::get( 'epc_notify_tier_upgrade' ) !== '1' ) {
            return;
        }

        global $wpdb;
        $member = $wpdb->get_row(
            $wpdb->prepare( "SELECT first_name, email FROM {$wpdb->prefix}epc_members WHERE id = %d", $member_id )
        );

        if ( ! $member || empty( $member->email ) ) {
            return;
        }

        wp_mail(
            $member->email,
            sprintf( '[%s] Αναβαθμίστηκες σε %s!', $this->club_name(), ucfirst( $new_tier ) ),
            sprintf(
                "Γεια σου %s!\n\nΣυγχαρητήρια! Αναβαθμίστηκες από %s σε %s!\nΑπόλαυσε τα νέα σου προνόμια.\n\nΕυχαριστούμε!",
                $member->first_name,
                ucfirst( $old_tier ),
                ucfirst( $new_tier )
            )
        );

        // Also notify admin
        wp_mail(
            $this->admin_email(),
            sprintf( '[%s] Αναβάθμιση: %s → %s', $this->club_name(), ucfirst( $old_tier ), ucfirst( $new_tier ) ),
            sprintf(
                "Το μέλος %s (%s) αναβαθμίστηκε από %s σε %s.",
                $member->first_name,
                $member->email,
                ucfirst( $old_tier ),
                ucfirst( $new_tier )
            )
        );
    }
    */
}
