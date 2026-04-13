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

        ?>
        <h2 style="margin-top:30px;">
            <span class="dashicons dashicons-groups" style="color:#4f46e5;"></span>
            <?php echo esc_html( $club_name ); ?>
        </h2>

        <?php if ( $member ) : ?>
            <?php
            $referral_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE referrer_member_id = %d AND status = 'completed'",
                    (int) $member['id']
                )
            );
            ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
                    <td>
                        <span class="epc-profile-badge epc-profile-badge-<?php echo esc_attr( $member['status'] ); ?>">
                            <?php echo $member['status'] === 'active'
                                ? esc_html__( 'Ενεργό Μέλος', 'epappous-club' )
                                : esc_html( ucfirst( $member['status'] ) ); ?>
                        </span>
                        <span class="epc-profile-tier-badge" style="margin-left:8px;">
                            <?php echo esc_html( ucfirst( $member['tier'] ) ); ?>
                        </span>
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
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
                    <td>
                        <span style="color:#ef4444;font-weight:600;">
                            <?php esc_html_e( 'Δεν είναι μέλος του club', 'epappous-club' ); ?>
                        </span>
                    </td>
                </tr>
            </table>
        <?php endif; ?>

        <?php
        // ── Notes — ALWAYS shown for every user ──
        $this->render_notes( $user->ID, $nonce );
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
        <h3 style="margin-top:24px;">
            <span class="dashicons dashicons-edit" style="color:#4f46e5;"></span>
            <?php esc_html_e( 'Σημειώσεις Admin', 'epappous-club' ); ?>
        </h3>

        <div class="epc-notes-section">
            <div style="margin-bottom:16px;display:flex;gap:8px;align-items:flex-start;">
                <textarea id="epc-new-note" rows="2" style="flex:1;min-width:0;border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:13px;"
                          placeholder="<?php esc_attr_e( 'Γράψε σημείωση...', 'epappous-club' ); ?>"></textarea>
                <button type="button" class="button button-primary epc-add-note-btn"
                        data-user-id="<?php echo (int) $wp_user_id; ?>"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <?php esc_html_e( 'Προσθήκη', 'epappous-club' ); ?>
                </button>
            </div>

            <div id="epc-notes-timeline">
                <?php if ( empty( $notes ) ) : ?>
                    <p class="epc-no-notes" style="color:#9ca3af;font-style:italic;">
                        <?php esc_html_e( 'Δεν υπάρχουν σημειώσεις.', 'epappous-club' ); ?>
                    </p>
                <?php else : ?>
                    <?php foreach ( $notes as $note ) : ?>
                        <div class="epc-note-item" data-note-id="<?php echo (int) $note['id']; ?>" style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #e5e7eb;">
                            <div style="flex:0 0 90px;font-size:12px;color:#6b7280;font-weight:600;">
                                <?php echo esc_html( date_i18n( 'd.m.y', strtotime( $note['created_at'] ) ) ); ?>
                                <br /><span style="font-weight:400;color:#9ca3af;"><?php echo esc_html( date_i18n( 'H:i', strtotime( $note['created_at'] ) ) ); ?></span>
                            </div>
                            <div style="flex:1;font-size:13px;color:#374151;white-space:pre-wrap;"><?php echo esc_html( $note['note'] ); ?></div>
                            <div style="flex:0 0 auto;text-align:right;">
                                <small style="color:#9ca3af;"><?php echo esc_html( $note['author_name'] ?? '' ); ?></small>
                                <br />
                                <button type="button" class="epc-delete-note-btn" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:12px;padding:0;"
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
}
