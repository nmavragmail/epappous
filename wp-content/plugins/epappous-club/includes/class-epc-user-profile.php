<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * User Profile Integration
 *
 * - Pappou Club info (only if user is a member)
 * - Admin notes timeline (always, for ALL users)
 */
class EPC_User_Profile {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'show_user_profile', [ $this, 'render_section' ], 1 );
        add_action( 'edit_user_profile', [ $this, 'render_section' ], 1 );

        add_action( 'admin_head-profile.php', [ $this, 'hide_wp_profile_sections' ] );
        add_action( 'admin_head-user-edit.php', [ $this, 'hide_wp_profile_sections' ] );

        add_action( 'wp_ajax_epc_add_note', [ $this, 'ajax_add_note' ] );
        add_action( 'wp_ajax_epc_delete_note', [ $this, 'ajax_delete_note' ] );
        add_action( 'wp_ajax_epc_toggle_membership', [ $this, 'ajax_toggle_membership' ] );
        add_action( 'wp_ajax_epc_adjust_points', [ $this, 'ajax_adjust_points' ] );
        add_action( 'wp_ajax_epc_search_members', [ $this, 'ajax_search_members' ] );
    }

    /**
     * Hide color scheme, syntax highlighting, keyboard shortcuts, toolbar rows.
     */
    public function hide_wp_profile_sections() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        ?>
        <style>
            .user-admin-color-wrap,
            .user-syntax-highlighting-wrap,
            .user-comment-shortcuts-wrap,
            .show-admin-bar.user-admin-bar-front-wrap { display: none !important; }
        </style>
        <?php
    }

    public function render_section( $user ) {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        global $wpdb;

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}epc_members WHERE user_id = %d OR email = %s LIMIT 1",
                $user->ID,
                $user->user_email
            ),
            ARRAY_A
        );

        $club_name = EPC_Settings::get( 'epc_club_name' );
        $currency  = EPC_Settings::get( 'epc_currency_symbol' );
        $nonce     = wp_create_nonce( 'epc_admin_nonce' );
        $is_member = ! empty( $member );

        ?>
        <div class="epc-profile-box">
            <div class="epc-profile-box-header">
                <span class="dashicons dashicons-groups"></span>
                <?php echo esc_html( $club_name ); ?>
            </div>
            <div class="epc-profile-box-body">

                <?php if ( $is_member ) : ?>
                    <?php
                    $referral_count = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE referrer_member_id = %d AND status = 'completed'",
                            (int) $member['id']
                        )
                    );
                    $is_active = $member['status'] === 'active';
                    ?>
                    <table class="form-table epc-profile-table">
                        <tr>
                            <th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
                            <td>
                                <span class="epc-profile-badge epc-profile-badge-<?php echo esc_attr( $member['status'] ); ?>">
                                    <?php echo $is_active
                                        ? esc_html__( 'Ενεργό Μέλος', 'epappous-club' )
                                        : esc_html( ucfirst( $member['status'] ) ); ?>
                                </span>
                                <span class="epc-profile-tier-badge" style="margin-left:8px;">
                                    <?php echo esc_html( ucfirst( $member['tier'] ) ); ?>
                                </span>
                                <label class="epc-membership-toggle" style="margin-left:16px;">
                                    <input type="checkbox" class="epc-membership-toggle-input"
                                           data-member-id="<?php echo (int) $member['id']; ?>"
                                           data-nonce="<?php echo esc_attr( $nonce ); ?>"
                                           <?php checked( $is_active ); ?> />
                                    <span class="epc-membership-toggle-slider"></span>
                                    <span class="epc-membership-toggle-label">
                                        <?php echo $is_active
                                            ? esc_html__( 'Ενεργό', 'epappous-club' )
                                            : esc_html__( 'Ανενεργό', 'epappous-club' ); ?>
                                    </span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></th>
                            <td>
                                <strong class="epc-points-display" style="font-size:18px;color:#4f46e5;">
                                    <?php echo esc_html( $currency . ' ' . number_format( (int) $member['points'] ) ); ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Προσαρμογή Πόντων', 'epappous-club' ); ?></th>
                            <td>
                                <div class="epc-points-adjust-row">
                                    <select class="epc-points-adjust-type">
                                        <option value="add">+ <?php esc_html_e( 'Προσθήκη', 'epappous-club' ); ?></option>
                                        <option value="remove">− <?php esc_html_e( 'Αφαίρεση', 'epappous-club' ); ?></option>
                                    </select>
                                    <input type="number" class="epc-points-adjust-amount" min="1" placeholder="<?php esc_attr_e( 'Πόντοι', 'epappous-club' ); ?>" />
                                    <input type="text" class="epc-points-adjust-reason" placeholder="<?php esc_attr_e( 'Λόγος (προαιρετικό)', 'epappous-club' ); ?>" />
                                    <button type="button" class="button button-primary epc-points-adjust-btn"
                                            data-member-id="<?php echo (int) $member['id']; ?>"
                                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                        <?php esc_html_e( 'Εφαρμογή', 'epappous-club' ); ?>
                                    </button>
                                </div>
                                <span class="epc-points-adjust-msg" style="display:none;"></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Γενέθλια', 'epappous-club' ); ?></th>
                            <td>
                                <?php if ( ! empty( $member['date_of_birth'] ) ) : ?>
                                    <strong><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $member['date_of_birth'] ) ) ); ?></strong>
                                <?php else : ?>
                                    <em style="color:#9ca3af;"><?php esc_html_e( 'Δεν έχει οριστεί', 'epappous-club' ); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Referrals', 'epappous-club' ); ?></th>
                            <td>
                                <strong><?php echo (int) $referral_count; ?></strong>
                                <?php esc_html_e( 'φίλοι', 'epappous-club' ); ?>
                                <br /><code><?php echo esc_html( $member['referral_code'] ); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Μέλος από', 'epappous-club' ); ?></th>
                            <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $member['joined_at'] ) ) ); ?></td>
                        </tr>
                    </table>
                <?php else : ?>
                    <table class="form-table epc-profile-table">
                        <tr>
                            <th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
                            <td>
                                <span style="color:#ef4444;font-weight:600;">
                                    <?php esc_html_e( 'Δεν είναι μέλος του club', 'epappous-club' ); ?>
                                </span>
                                <button type="button" class="button epc-enroll-member-btn"
                                        data-user-id="<?php echo (int) $user->ID; ?>"
                                        data-nonce="<?php echo esc_attr( $nonce ); ?>"
                                        style="margin-left:12px;">
                                    <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-top:-2px;"></span>
                                    <?php esc_html_e( 'Εγγραφή στο Club', 'epappous-club' ); ?>
                                </button>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <?php $this->render_notes( $user->ID, $nonce ); ?>

            </div>
        </div>
        <?php
    }

    /**
     * Render admin notes timeline for a WordPress user (regardless of club membership).
     */
    private function render_notes( int $wp_user_id, string $nonce ) {
        global $wpdb;

        $notes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.*, u.display_name AS author_name
                 FROM {$wpdb->prefix}epc_member_notes n
                 LEFT JOIN {$wpdb->users} u ON n.author_id = u.ID
                 WHERE n.user_id = %d
                 ORDER BY n.created_at DESC",
                $wp_user_id
            ),
            ARRAY_A
        ) ?: [];
        ?>
        <div class="epc-notes-section">
            <h3 class="epc-notes-heading">
                <span class="dashicons dashicons-edit"></span>
                <?php esc_html_e( 'Σημειώσεις Admin', 'epappous-club' ); ?>
            </h3>

            <div class="epc-note-add-row">
                <label><?php esc_html_e( 'Νέα σημείωση', 'epappous-club' ); ?></label>
                <div class="epc-note-add-controls">
                    <textarea id="epc-new-note" rows="2"
                              placeholder="<?php esc_attr_e( 'Γράψε σημείωση...', 'epappous-club' ); ?>"></textarea>
                    <button type="button" class="button button-primary epc-add-note-btn"
                            data-user-id="<?php echo (int) $wp_user_id; ?>"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Προσθήκη', 'epappous-club' ); ?>
                    </button>
                </div>
                <span class="epc-note-saved-msg" style="display:none;">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e( 'Αποθηκεύτηκε!', 'epappous-club' ); ?>
                </span>
            </div>

            <div class="epc-note-history">
                <label><?php esc_html_e( 'Ιστορικό', 'epappous-club' ); ?></label>
                <div id="epc-notes-timeline">
                    <?php if ( empty( $notes ) ) : ?>
                        <p class="epc-no-notes">
                            <?php esc_html_e( 'Δεν υπάρχουν σημειώσεις.', 'epappous-club' ); ?>
                        </p>
                    <?php else : ?>
                        <?php foreach ( $notes as $note ) : ?>
                            <div class="epc-note-item" data-note-id="<?php echo (int) $note['id']; ?>">
                                <div class="epc-note-date">
                                    <?php echo esc_html( date_i18n( 'd.m.y', strtotime( $note['created_at'] ) ) ); ?>
                                    <br /><span><?php echo esc_html( date_i18n( 'H:i', strtotime( $note['created_at'] ) ) ); ?></span>
                                </div>
                                <div class="epc-note-body"><?php echo esc_html( $note['note'] ); ?></div>
                                <div class="epc-note-meta">
                                    <small><?php echo esc_html( $note['author_name'] ?? '' ); ?></small>
                                    <br />
                                    <button type="button" class="epc-delete-note-btn"
                                            data-note-id="<?php echo (int) $note['id']; ?>"
                                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                        <?php esc_html_e( 'Διαγραφή', 'epappous-club' ); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX ──

    public function ajax_add_note() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $user_id   = (int) ( $_POST['user_id'] ?? 0 );
        $note_text = sanitize_textarea_field( $_POST['note'] ?? '' );

        if ( $user_id < 1 || empty( $note_text ) ) {
            wp_send_json_error( 'Missing data' );
        }

        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}epc_member_notes",
            [
                'user_id'   => $user_id,
                'author_id' => get_current_user_id(),
                'note'      => $note_text,
            ],
            [ '%d', '%d', '%s' ]
        );

        $note_id = (int) $wpdb->insert_id;
        $author  = wp_get_current_user();

        wp_send_json_success( [
            'id'          => $note_id,
            'note'        => $note_text,
            'author_name' => $author->display_name,
            'date'        => date_i18n( 'd.m.y' ),
            'time'        => date_i18n( 'H:i' ),
        ] );
    }

    public function ajax_delete_note() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $note_id = (int) ( $_POST['note_id'] ?? 0 );
        if ( $note_id < 1 ) {
            wp_send_json_error( 'Missing note_id' );
        }

        global $wpdb;
        $wpdb->delete(
            "{$wpdb->prefix}epc_member_notes",
            [ 'id' => $note_id ],
            [ '%d' ]
        );

        wp_send_json_success();
    }

    /**
     * Toggle membership status (active/inactive) or enroll a non-member.
     */
    public function ajax_toggle_membership() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;

        $member_id = (int) ( $_POST['member_id'] ?? 0 );
        $user_id   = (int) ( $_POST['user_id'] ?? 0 );
        $enable    = ! empty( $_POST['enable'] );

        if ( $member_id > 0 ) {
            $new_status = $enable ? 'active' : 'inactive';
            $wpdb->update(
                "{$wpdb->prefix}epc_members",
                [ 'status' => $new_status ],
                [ 'id' => $member_id ],
                [ '%s' ],
                [ '%d' ]
            );

            wp_send_json_success( [
                'status'      => $new_status,
                'status_label' => $new_status === 'active'
                    ? __( 'Ενεργό Μέλος', 'epappous-club' )
                    : __( 'Inactive', 'epappous-club' ),
            ] );
        }

        if ( $user_id > 0 ) {
            $wp_user = get_userdata( $user_id );
            if ( ! $wp_user ) {
                wp_send_json_error( 'User not found' );
            }

            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}epc_members WHERE user_id = %d OR email = %s LIMIT 1",
                    $user_id,
                    $wp_user->user_email
                )
            );

            if ( $existing ) {
                wp_send_json_error( 'Already a member' );
            }

            $referral_code = EPC_Referral::generate_code();

            $wpdb->insert(
                "{$wpdb->prefix}epc_members",
                [
                    'user_id'       => $user_id,
                    'first_name'    => $wp_user->first_name ?: $wp_user->display_name,
                    'last_name'     => $wp_user->last_name ?: '',
                    'email'         => $wp_user->user_email,
                    'phone'         => '',
                    'referral_code' => $referral_code,
                    'points'        => 0,
                    'tier'          => 'basic',
                    'status'        => 'active',
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );

            $new_member_id = (int) $wpdb->insert_id;

            do_action( 'epc_member_registered', $new_member_id, [
                'email'      => $wp_user->user_email,
                'first_name' => $wp_user->first_name ?: $wp_user->display_name,
                'last_name'  => $wp_user->last_name ?: '',
            ] );

            wp_send_json_success( [ 'reload' => true ] );
        }

        wp_send_json_error( 'Missing data' );
    }

    /**
     * AJAX: Add or remove points from a member (manual adjustment).
     */
    public function ajax_adjust_points() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $member_id = (int) ( $_POST['member_id'] ?? 0 );
        $type      = sanitize_text_field( $_POST['type'] ?? 'add' );
        $amount    = (int) ( $_POST['amount'] ?? 0 );
        $reason    = sanitize_text_field( $_POST['reason'] ?? '' );

        if ( $member_id < 1 || $amount < 1 ) {
            wp_send_json_error( __( 'Λείπουν δεδομένα', 'epappous-club' ) );
        }

        global $wpdb;

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points FROM {$wpdb->prefix}epc_members WHERE id = %d",
                $member_id
            )
        );

        if ( ! $member ) {
            wp_send_json_error( __( 'Δεν βρέθηκε μέλος', 'epappous-club' ) );
        }

        $points_delta = $type === 'remove' ? -$amount : $amount;
        $admin_id     = get_current_user_id();

        if ( $type === 'remove' ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_members SET points = GREATEST(0, CAST(points AS SIGNED) - %d) WHERE id = %d",
                    $amount,
                    $member_id
                )
            );
        } else {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                    $amount,
                    $member_id
                )
            );
        }

        $log_reason = ! empty( $reason ) ? $reason : 'manual_adjustment';

        $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => $points_delta,
                'reason'         => $log_reason,
                'reference_type' => 'manual',
                'reference_id'   => $admin_id,
                'admin_user_id'  => $admin_id,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%d' ]
        );

        do_action( 'epc_points_changed', $member_id );

        $new_points = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT points FROM {$wpdb->prefix}epc_members WHERE id = %d",
                $member_id
            )
        );

        $admin = wp_get_current_user();

        wp_send_json_success( [
            'new_points'   => $new_points,
            'points_delta' => $points_delta,
            'admin_name'   => $admin->display_name,
            'date'         => date_i18n( 'd/m/Y H:i' ),
        ] );
    }

    /**
     * AJAX: Search members for points adjustment modal.
     */
    public function ajax_search_members() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $q = sanitize_text_field( $_GET['q'] ?? '' );
        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( [] );
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $q ) . '%';

        $members = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, first_name, last_name, email, points
                 FROM {$wpdb->prefix}epc_members
                 WHERE first_name LIKE %s OR last_name LIKE %s OR email LIKE %s
                 ORDER BY first_name ASC
                 LIMIT 10",
                $like,
                $like,
                $like
            ),
            ARRAY_A
        );

        $results = [];
        foreach ( $members as $m ) {
            $results[] = [
                'id'     => (int) $m['id'],
                'name'   => $m['first_name'] . ' ' . $m['last_name'],
                'email'  => $m['email'],
                'points' => (int) $m['points'],
            ];
        }

        wp_send_json_success( $results );
    }
}
