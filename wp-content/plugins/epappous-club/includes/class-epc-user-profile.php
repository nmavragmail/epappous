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
        add_action( 'show_user_profile', [ $this, 'render_section' ] );
        add_action( 'edit_user_profile', [ $this, 'render_section' ] );

        add_action( 'wp_ajax_epc_add_note', [ $this, 'ajax_add_note' ] );
        add_action( 'wp_ajax_epc_delete_note', [ $this, 'ajax_delete_note' ] );
        add_action( 'wp_ajax_epc_toggle_membership', [ $this, 'ajax_toggle_membership' ] );
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
                                <strong style="font-size:18px;color:#4f46e5;">
                                    <?php echo esc_html( $currency . ' ' . number_format( (int) $member['points'] ) ); ?>
                                </strong>
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
}
