<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Links epc_members rows to WordPress users by email and assigns the B2B King Pappou Club group.
 *
 * Flow:
 * - Front registration creates epc_members; if a WP user already exists with that email, we link user_id + assign group.
 * - When a WP/WC user is created later with an email that matches a club row (user_id empty), we link + assign group.
 * - Admin can add members manually; same rules apply.
 */
class EPC_Member_Sync {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'user_register', [ $this, 'on_user_register' ], 25, 1 );
        add_action( 'woocommerce_created_customer', [ $this, 'on_woocommerce_created_customer' ], 25, 3 );
        add_action( 'admin_post_epc_add_member', [ $this, 'handle_admin_add_member' ] );
        add_action( 'epc_member_registered', [ __CLASS__, 'on_member_registered_assign_b2b_group' ], 5, 2 );
    }

    /**
     * After any club registration path: ensure linked WP user is in the B2B King Pappou Club group.
     *
     * @param int   $member_id epc_members.id.
     * @param array $context   Hook payload (unused; kept for signature compatibility).
     */
    public static function on_member_registered_assign_b2b_group( int $member_id, array $context = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        self::ensure_b2b_group_for_member( $member_id );
    }

    /**
     * Assign B2B King group when the member row is linked to a WP user (active member only).
     */
    public static function ensure_b2b_group_for_member( int $member_id ): void {
        if ( $member_id < 1 || EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        global $wpdb;
        $uid = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}epc_members WHERE id = %d AND status = 'active'",
                $member_id
            )
        );

        if ( $uid > 0 ) {
            EPC_B2BKing::assign_pappou_club_group( $uid );
        }
    }

    /**
     * One-time migration: put every active club member’s WP user into the configured B2B King group.
     */
    public static function backfill_b2bking_club_group_for_all_members(): void {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, user_id, email FROM {$wpdb->prefix}epc_members WHERE status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $member_id = (int) ( $row['id'] ?? 0 );
            $uid       = (int) ( $row['user_id'] ?? 0 );
            $email     = sanitize_email( (string) ( $row['email'] ?? '' ) );

            // Existing members can have no linked user_id; try linking by email first.
            if ( $uid < 1 && is_email( $email ) ) {
                self::link_member_to_wp_user_by_email( $member_id, $email, false );
                $uid = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->prefix}epc_members WHERE id = %d",
                        $member_id
                    )
                );
            }

            if ( $uid > 0 ) {
                EPC_B2BKing::assign_pappou_club_group( $uid );
            }
        }
    }

    /**
     * After club form inserts a row, link to existing WP account with same email if any.
     */
    public static function after_club_registration( int $member_id, string $email ): void {
        self::link_member_to_wp_user_by_email( $member_id, $email, true );
        self::ensure_b2b_group_for_member( $member_id );
    }

    /**
     * Link epc_members.id to WP user by email; optionally assign B2B group.
     *
     * @param bool $assign_group Whether to call B2B King assign (false if user already in correct group is OK to skip — we assign to enforce club).
     */
    public static function link_member_to_wp_user_by_email( int $member_id, string $email, bool $assign_group = true ): bool {
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) {
            return false;
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return false;
        }

        global $wpdb;
        $table = "{$wpdb->prefix}epc_members";
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM {$table} WHERE id = %d", $member_id ) );
        if ( ! $row ) {
            return false;
        }

        $existing_uid = (int) $row->user_id;
        if ( $existing_uid > 0 && $existing_uid !== (int) $user->ID ) {
            return false;
        }

        $wpdb->update(
            $table,
            [ 'user_id' => (int) $user->ID ],
            [ 'id' => $member_id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $assign_group ) {
            EPC_B2BKing::assign_pappou_club_group( (int) $user->ID );
        }

        return true;
    }

    /**
     * New WP user: attach to matching epc_members row (same email, no conflicting user_id).
     */
    public function on_user_register( $user_id ) {
        $this->sync_wp_user_id( (int) $user_id );
    }

    /**
     * WooCommerce customer created (may not always fire user_register depending on WC version/settings).
     */
    public function on_woocommerce_created_customer( $customer_id, $new_customer_data, $password_generated ) {
        unset( $new_customer_data, $password_generated );
        $this->sync_wp_user_id( (int) $customer_id );
    }

    private function sync_wp_user_id( int $user_id ): void {
        if ( $user_id < 1 ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $email = sanitize_email( $user->user_email );
        if ( ! is_email( $email ) ) {
            return;
        }

        global $wpdb;
        $table = "{$wpdb->prefix}epc_members";
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id FROM {$table} WHERE email = %s LIMIT 1",
                $email
            )
        );

        if ( ! $row ) {
            return;
        }

        $mid = (int) $row->id;
        $old = (int) $row->user_id;

        if ( $old > 0 && $old !== $user_id ) {
            return;
        }

        $wpdb->update(
            $table,
            [ 'user_id' => $user_id ],
            [ 'id' => $mid ],
            [ '%d' ],
            [ '%d' ]
        );

        EPC_B2BKing::assign_pappou_club_group( $user_id );
    }

    /**
     * Admin: add member (same fields as front form, no AJAX).
     */
    public function handle_admin_add_member(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Δεν επιτρέπεται.', 'epappous-club' ) );
        }

        check_admin_referer( 'epc_add_member' );

        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            wp_safe_redirect(
                add_query_arg( [ 'page' => 'epc-members', 'epc_msg' => 'disabled' ], admin_url( 'admin.php' ) )
            );
            exit;
        }

        $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

        if ( empty( $first ) || empty( $last ) || empty( $email ) || ! is_email( $email ) ) {
            wp_safe_redirect(
                add_query_arg( [ 'page' => 'epc-members', 'epc_msg' => 'invalid' ], admin_url( 'admin.php' ) )
            );
            exit;
        }

        global $wpdb;
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE email = %s",
                $email
            )
        );
        if ( $exists ) {
            wp_safe_redirect(
                add_query_arg( [ 'page' => 'epc-members', 'epc_msg' => 'exists' ], admin_url( 'admin.php' ) )
            );
            exit;
        }

        $referral_code = EPC_Referral::generate_code();
        $linked_user   = get_user_by( 'email', $email );
        $wp_uid        = $linked_user ? (int) $linked_user->ID : null;

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_members",
            [
                'user_id'       => $wp_uid,
                'first_name'    => $first,
                'last_name'     => $last,
                'email'         => $email,
                'phone'         => $phone,
                'date_of_birth' => null,
                'referral_code' => $referral_code,
                'points'        => 0,
                'tier'          => 'basic',
                'status'        => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            wp_safe_redirect(
                add_query_arg( [ 'page' => 'epc-members', 'epc_msg' => 'error' ], admin_url( 'admin.php' ) )
            );
            exit;
        }

        $member_id = (int) $wpdb->insert_id;

        self::after_club_registration( $member_id, $email );

        do_action(
            'epc_member_registered',
            $member_id,
            [
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
                'source'     => 'admin',
            ]
        );

        wp_safe_redirect(
            add_query_arg( [ 'page' => 'epc-members', 'epc_msg' => 'created' ], admin_url( 'admin.php' ) )
        );
        exit;
    }
}
