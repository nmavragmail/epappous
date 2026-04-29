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

        // If a user is put into the B2B King Pappou Club group, ensure a club member row exists.
        add_action( 'updated_user_meta', [ __CLASS__, 'maybe_create_member_on_b2b_group_change' ], 20, 4 );
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
     * Backfill members based on B2B King group assignment.
     * Any WP user with meta b2bking_customergroup = configured club group will be ensured as active club member.
     */
    public static function backfill_members_from_b2bking_group(): void {
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        if ( ! EPC_B2BKing::is_active() ) {
            return;
        }

        $gid = EPC_B2BKing::get_configured_group_id();
        if ( $gid < 1 ) {
            return;
        }

        $batch = 200;
        $offset = 0;
        // Batched — never unbounded `number => 0` in a single request.
        while ( true ) {
            $users = get_users( [
                'fields'     => 'ID',
                'number'     => $batch,
                'offset'     => $offset,
                'meta_key'   => 'b2bking_customergroup',
                'meta_value' => (string) $gid,
                'orderby'    => 'ID',
                'order'      => 'ASC',
            ] );

            if ( empty( $users ) ) {
                break;
            }

            foreach ( $users as $u ) {
                $uid = (int) ( is_object( $u ) ? $u->ID : $u );
                if ( $uid > 0 ) {
                    self::ensure_member_row_for_user( $uid, true );
                }
            }

            if ( count( $users ) < $batch ) {
                break;
            }
            $offset += $batch;
        }
    }

    /**
     * If a user is assigned to Pappou Club group in B2B King, ensure club membership exists.
     */
    public static function maybe_create_member_on_b2b_group_change( $meta_id, $user_id, $meta_key, $meta_value ): void {
        unset( $meta_id );
        if ( 'b2bking_customergroup' !== (string) $meta_key ) {
            return;
        }
        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }
        $gid = EPC_B2BKing::get_configured_group_id();
        if ( $gid < 1 ) {
            return;
        }

        if ( (string) $meta_value !== (string) $gid ) {
            return;
        }

        self::ensure_member_row_for_user( (int) $user_id, true );
    }

    /**
     * Ensure epc_members row exists for a given WP user.
     * When $silent is true, it does not fire epc_member_registered (avoids emails).
     */
    public static function ensure_member_row_for_user( int $user_id, bool $silent = true ): int {
        if ( $user_id < 1 ) {
            return 0;
        }

        $wp_user = get_userdata( $user_id );
        if ( ! $wp_user ) {
            return 0;
        }

        $email = sanitize_email( (string) $wp_user->user_email );
        if ( ! is_email( $email ) ) {
            return 0;
        }

        global $wpdb;
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status FROM {$wpdb->prefix}epc_members WHERE user_id = %d OR email = %s LIMIT 1",
                $user_id,
                $email
            ),
            ARRAY_A
        );

        if ( $existing ) {
            $mid = (int) $existing['id'];
            // Ensure active + linked user_id
            $wpdb->update(
                "{$wpdb->prefix}epc_members",
                [
                    'user_id' => $user_id,
                    'status'  => 'active',
                ],
                [ 'id' => $mid ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            self::ensure_b2b_group_for_member( $mid );
            return $mid;
        }

        $referral_code = EPC_Referral::generate_code();
        $first         = $wp_user->first_name ?: $wp_user->display_name;
        $last          = $wp_user->last_name ?: '';

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}epc_members",
            [
                'user_id'       => $user_id,
                'first_name'    => sanitize_text_field( $first ),
                'last_name'     => sanitize_text_field( $last ),
                'email'         => $email,
                'phone'         => '',
                'date_of_birth' => null,
                'referral_code' => $referral_code,
                'points'        => 0,
                'tier'          => 'basic',
                'status'        => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return 0;
        }

        $member_id = (int) $wpdb->insert_id;
        self::ensure_b2b_group_for_member( $member_id );

        if ( ! $silent ) {
            do_action( 'epc_member_registered', $member_id, [
                'email'      => $email,
                'first_name' => sanitize_text_field( $first ),
                'last_name'  => sanitize_text_field( $last ),
                'source'     => 'b2bking_backfill',
            ] );
        }

        return $member_id;
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
                add_query_arg( [ 'page' => 'epc-dashboard', 'epc_msg' => 'disabled' ], admin_url( 'admin.php' ) )
            );
            exit;
        }

        $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

        if ( empty( $first ) || empty( $last ) || empty( $email ) || ! is_email( $email ) ) {
            wp_safe_redirect(
                add_query_arg( [ 'page' => 'epc-dashboard', 'epc_msg' => 'invalid' ], admin_url( 'admin.php' ) )
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
                add_query_arg( [ 'page' => 'epc-dashboard', 'epc_msg' => 'exists' ], admin_url( 'admin.php' ) )
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
                add_query_arg( [ 'page' => 'epc-dashboard', 'epc_msg' => 'error' ], admin_url( 'admin.php' ) )
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
            add_query_arg( [ 'page' => 'epc-dashboard', 'epc_msg' => 'created' ], admin_url( 'admin.php' ) )
        );
        exit;
    }
}
