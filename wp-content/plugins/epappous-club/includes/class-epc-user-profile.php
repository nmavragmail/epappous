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

    const USER_META_CASSETTE         = 'epc_cassette_gift_received';
    const USER_META_CASSETTE_DATE    = 'epc_cassette_gift_date';
    const USER_META_CASSETTE_EDITED_BY = 'epc_cassette_gift_edited_by';
    const USER_META_CASSETTE_EDITED_AT = 'epc_cassette_gift_edited_at';

    private static $instance = null;

    /**
     * Last admin who saved cassette gift fields (ΝΑΙ/ΟΧΙ/ημερομηνία) for display on profile and orders.
     */
    public static function get_cassette_audit_for_display( int $wp_user_id ): ?array {
        $by = (int) get_user_meta( $wp_user_id, self::USER_META_CASSETTE_EDITED_BY, true );
        $at = get_user_meta( $wp_user_id, self::USER_META_CASSETTE_EDITED_AT, true );
        if ( $by < 1 || empty( $at ) || ! is_string( $at ) ) {
            return null;
        }
        $u = get_userdata( $by );
        $name = $u ? $u->display_name : sprintf( __( 'User #%d', 'epappous-club' ), $by );
        $ts   = strtotime( $at );
        $when = $ts ? date_i18n( 'd/m/Y H:i', $ts ) : '';
        return [
            'user_id'     => $by,
            'editor_name' => $name,
            'edited_at'   => $when,
        ];
    }

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
        add_action( 'wp_ajax_epc_update_note', [ $this, 'ajax_update_note' ] );
        add_action( 'wp_ajax_epc_save_cassette_gift', [ $this, 'ajax_save_cassette_gift' ] );
        add_action( 'wp_ajax_epc_toggle_membership', [ $this, 'ajax_toggle_membership' ] );
        add_action( 'wp_ajax_epc_adjust_points', [ $this, 'ajax_adjust_points' ] );
        add_action( 'wp_ajax_epc_search_members', [ $this, 'ajax_search_members' ] );
    }

    /**
     * Hide color scheme, syntax highlighting, keyboard shortcuts, toolbar rows.
     */
    public function hide_wp_profile_sections() {
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
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
        if ( ! EPC_Capabilities::current_user_can_manage_club() || ! EPC_Capabilities::current_user_can_edit_wp_user( (int) $user->ID ) ) {
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

        $club_name    = EPC_Settings::get( 'epc_club_name' );
        $currency     = EPC_Settings::get( 'epc_currency_symbol' );
        $point_value  = (float) EPC_Settings::get( 'epc_points_value_euro' );
        $nonce        = wp_create_nonce( 'epc_admin_nonce' );
        $is_member    = ! empty( $member );

        ?>
        <div class="epc-profile-box">
            <div class="epc-profile-box-header">
                <span class="dashicons dashicons-groups"></span>
                <?php echo esc_html( $club_name ); ?>
            </div>
            <div class="epc-profile-box-body">

                <?php if ( $is_member ) : ?>
                    <?php
                    $referred_members = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT DISTINCT m.id, m.user_id, m.first_name, m.last_name, m.email
                             FROM {$wpdb->prefix}epc_members m
                             LEFT JOIN {$wpdb->prefix}epc_referrals r
                               ON r.referred_member_id = m.id
                              AND r.referrer_member_id = %d
                             WHERE (m.referred_by = %d OR r.id IS NOT NULL)
                               AND m.id <> %d
                             ORDER BY m.first_name ASC, m.last_name ASC, m.email ASC",
                            (int) $member['id'],
                            (int) $member['id'],
                            (int) $member['id']
                        )
                    ) ?: [];
                    $referral_count = count( $referred_members );
                    $referrer_member = null;
                    if ( ! empty( $member['referred_by'] ) ) {
                        $referrer_member = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT id, user_id, first_name, last_name, email
                                 FROM {$wpdb->prefix}epc_members
                                 WHERE id = %d
                                 LIMIT 1",
                                (int) $member['referred_by']
                            ),
                            ARRAY_A
                        );
                    }
                    $is_active = $member['status'] === 'active';

                    $checkout_total = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT COALESCE(SUM(ABS(points)),0) AS total_pts
                             FROM {$wpdb->prefix}epc_points_log
                             WHERE member_id = %d AND reason = 'checkout_redemption'",
                            (int) $member['id']
                        )
                    );
                    $gift_total = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT COALESCE(SUM(ABS(points)),0) AS total_pts, COUNT(*) AS total_count
                             FROM {$wpdb->prefix}epc_points_log
                             WHERE member_id = %d AND reason = 'gift_redemption'",
                            (int) $member['id']
                        )
                    );
                    $checkout_pts  = (int) $checkout_total->total_pts;
                    $checkout_euro = $checkout_pts * $point_value;
                    $gift_pts      = (int) $gift_total->total_pts;
                    $gift_count    = (int) $gift_total->total_count;

                    $redemption_logs = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT pl.*
                             FROM {$wpdb->prefix}epc_points_log pl
                             WHERE pl.member_id = %d
                               AND pl.reason IN ('checkout_redemption','gift_redemption')
                             ORDER BY pl.created_at DESC
                             LIMIT 20",
                            (int) $member['id']
                        ),
                        ARRAY_A
                    ) ?: [];

                    $log_url = admin_url( 'admin.php?page=epc-points-log&member_id=' . (int) $member['id'] );
                    ?>

                    <!-- Status row -->
                    <div class="epc-pf-row">
                        <span class="epc-pf-label"><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value">
                            <span class="epc-profile-badge epc-profile-badge-<?php echo esc_attr( $member['status'] ); ?>">
                                <?php echo $is_active ? esc_html__( 'Ενεργό Μέλος', 'epappous-club' ) : esc_html( ucfirst( $member['status'] ) ); ?>
                            </span>
                            <label class="epc-membership-toggle">
                                <input type="checkbox" class="epc-membership-toggle-input"
                                       data-member-id="<?php echo (int) $member['id']; ?>"
                                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                                       <?php checked( $is_active ); ?> />
                                <span class="epc-membership-toggle-slider"></span>
                                <span class="epc-membership-toggle-label">
                                    <?php echo $is_active ? esc_html__( 'Ενεργό', 'epappous-club' ) : esc_html__( 'Ανενεργό', 'epappous-club' ); ?>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Points row -->
                    <div class="epc-pf-row">
                        <span class="epc-pf-label"><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value">
                            <strong class="epc-points-display"><?php echo esc_html( $currency . ' ' . number_format( (int) $member['points'] ) ); ?></strong>
                            <a href="<?php echo esc_url( $log_url ); ?>" class="epc-points-history-link"><?php esc_html_e( 'Αναλυτικά', 'epappous-club' ); ?></a>
                        </div>
                    </div>

                    <!-- Redemption row -->
                    <div class="epc-pf-row epc-pf-row--top">
                        <span class="epc-pf-label"><?php esc_html_e( 'Εξαργύρωση', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value epc-pf-value--col">
                            <div class="epc-redeem-summary">
                                <div class="epc-redeem-summary-item">
                                    <span class="epc-redeem-summary-label">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <?php esc_html_e( 'Έκπτωση checkout', 'epappous-club' ); ?>
                                    </span>
                                    <span class="epc-redeem-summary-value">
                                        <?php echo esc_html( $currency . ' ' . number_format( $checkout_pts ) ); ?>
                                        <?php if ( $checkout_euro > 0 ) : ?>
                                            <em>(<?php echo esc_html( number_format( $checkout_euro, 2 ) . ' €' ); ?>)</em>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="epc-redeem-summary-item">
                                    <span class="epc-redeem-summary-label">
                                        <span class="dashicons dashicons-cart"></span>
                                        <?php esc_html_e( 'Δώρα προϊόντα', 'epappous-club' ); ?>
                                    </span>
                                    <span class="epc-redeem-summary-value">
                                        <?php echo esc_html( $currency . ' ' . number_format( $gift_pts ) ); ?>
                                        <em>(<?php printf( esc_html__( '%d φορές', 'epappous-club' ), $gift_count ); ?>)</em>
                                    </span>
                                </div>
                            </div>
                            <?php if ( ! empty( $redemption_logs ) ) : ?>
                            <div class="epc-redeem-history">
                                <button type="button" class="epc-redeem-history-toggle">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                    <?php esc_html_e( 'Ιστορικό εξαργύρωσης', 'epappous-club' ); ?>
                                </button>
                                <div class="epc-redeem-history-list" style="display:none;">
                                    <?php foreach ( $redemption_logs as $rlog ) :
                                        $rpts  = abs( (int) $rlog['points'] );
                                        $rdate = date_i18n( 'd/m/Y H:i', strtotime( $rlog['created_at'] ) );
                                    ?>
                                    <div class="epc-redeem-history-item">
                                        <span class="epc-redeem-history-date"><?php echo esc_html( $rdate ); ?></span>
                                        <?php if ( $rlog['reason'] === 'checkout_redemption' ) : ?>
                                            <span class="epc-redeem-history-type checkout">
                                                <span class="dashicons dashicons-money-alt"></span>
                                                <?php printf( esc_html__( 'Έκπτωση %s %s', 'epappous-club' ), esc_html( number_format( $rpts ) ), esc_html( $currency ) ); ?>
                                                <?php if ( $point_value > 0 ) : ?>
                                                    <em>(<?php echo esc_html( number_format( $rpts * $point_value, 2 ) . ' €' ); ?>)</em>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $rlog['reference_id'] ) ) : ?>
                                                    — <a href="<?php echo esc_url( get_edit_post_link( (int) $rlog['reference_id'] ) ); ?>" target="_blank">
                                                        <?php printf( esc_html__( 'Παραγγελία #%d', 'epappous-club' ), (int) $rlog['reference_id'] ); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="epc-redeem-history-type gift">
                                                <span class="dashicons dashicons-cart"></span>
                                                <?php printf( esc_html__( 'Δώρο %s %s', 'epappous-club' ), esc_html( number_format( $rpts ) ), esc_html( $currency ) ); ?>
                                                <?php if ( ! empty( $rlog['reference_id'] ) ) : ?>
                                                    — <a href="<?php echo esc_url( get_edit_post_link( (int) $rlog['reference_id'] ) ); ?>" target="_blank">
                                                        <?php printf( esc_html__( 'Παραγγελία #%d', 'epappous-club' ), (int) $rlog['reference_id'] ); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Adjust points row -->
                    <div class="epc-pf-row epc-pf-row--top">
                        <span class="epc-pf-label"><?php esc_html_e( 'Προσαρμογή πόντων', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value epc-pf-value--col">
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
                        </div>
                    </div>

                    <div class="epc-pf-divider"></div>

                    <!-- Birthday row -->
                    <div class="epc-pf-row">
                        <span class="epc-pf-label"><?php esc_html_e( 'Γενέθλια', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value">
                            <?php if ( ! empty( $member['date_of_birth'] ) ) : ?>
                                <strong><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $member['date_of_birth'] ) ) ); ?></strong>
                            <?php else : ?>
                                <em class="epc-pf-empty"><?php esc_html_e( 'Δεν έχει οριστεί', 'epappous-club' ); ?></em>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Referrals row -->
                    <div class="epc-pf-row">
                        <span class="epc-pf-label"><?php esc_html_e( 'Referrals', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value">
                            <strong><?php echo (int) $referral_count; ?></strong>
                            <?php if ( $referral_count > 0 ) : ?>
                                <button type="button"
                                        class="button-link epc-referrals-open"
                                        data-target="#epc-referrals-modal-<?php echo (int) $member['id']; ?>">
                                    <?php esc_html_e( 'φίλοι', 'epappous-club' ); ?>
                                </button>
                            <?php else : ?>
                                <span class="epc-pf-sub"><?php esc_html_e( 'φίλοι', 'epappous-club' ); ?></span>
                            <?php endif; ?>
                            <code class="epc-pf-code"><?php echo esc_html( $member['referral_code'] ); ?></code>
                        </div>
                    </div>

                    <?php if ( $referral_count > 0 ) : ?>
                        <div id="epc-referrals-modal-<?php echo (int) $member['id']; ?>" class="epc-modal epc-referrals-modal" style="display:none;">
                            <div class="epc-modal-overlay"></div>
                            <div class="epc-modal-content epc-referrals-modal-content" role="dialog" aria-modal="true" aria-labelledby="epc-referrals-modal-title-<?php echo (int) $member['id']; ?>">
                                <div class="epc-modal-header">
                                    <h2 id="epc-referrals-modal-title-<?php echo (int) $member['id']; ?>"><?php esc_html_e( 'Φίλοι από referral', 'epappous-club' ); ?></h2>
                                    <button type="button" class="epc-modal-close" aria-label="<?php esc_attr_e( 'Κλείσιμο', 'epappous-club' ); ?>">&times;</button>
                                </div>
                                <div class="epc-modal-body">
                                    <div class="epc-referrals-list">
                                        <?php foreach ( $referred_members as $referred ) :
                                            $ref_name = trim( (string) ( $referred->first_name ?? '' ) . ' ' . (string) ( $referred->last_name ?? '' ) );
                                            if ( '' === $ref_name ) {
                                                $ref_name = __( 'Χωρίς όνομα', 'epappous-club' );
                                            }
                                            ?>
                                            <div class="epc-referral-person">
                                                <strong><?php echo esc_html( $ref_name ); ?></strong>
                                                <span><?php echo esc_html( $referred->email ?? '' ); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $referrer_member ) :
                        $referrer_name = trim( (string) ( $referrer_member['first_name'] ?? '' ) . ' ' . (string) ( $referrer_member['last_name'] ?? '' ) );
                        if ( '' === $referrer_name ) {
                            $referrer_name = __( 'Χωρίς όνομα', 'epappous-club' );
                        }
                        ?>
                        <div class="epc-pf-row">
                            <span class="epc-pf-label"><?php esc_html_e( 'Σύσταση από', 'epappous-club' ); ?></span>
                            <div class="epc-pf-value epc-referral-source">
                                <strong><?php echo esc_html( $referrer_name ); ?></strong>
                                <span><?php echo esc_html( $referrer_member['email'] ?? '' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Member since row -->
                    <div class="epc-pf-row">
                        <span class="epc-pf-label"><?php esc_html_e( 'Μέλος από', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value">
                            <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $member['joined_at'] ) ) ); ?>
                        </div>
                    </div>

                <?php else : ?>

                    <div class="epc-pf-row">
                        <span class="epc-pf-label"><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></span>
                        <div class="epc-pf-value">
                            <span class="epc-pf-not-member"><?php esc_html_e( 'Δεν είναι μέλος του club', 'epappous-club' ); ?></span>
                            <button type="button" class="button epc-enroll-member-btn"
                                    data-user-id="<?php echo (int) $user->ID; ?>"
                                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <?php esc_html_e( 'Εγγραφή στο Club', 'epappous-club' ); ?>
                            </button>
                        </div>
                    </div>

                <?php endif; ?>

            </div>
        </div>

        <?php $this->render_notes( $user->ID, $nonce ); ?>

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

        $show_cassette = ! EPC_Settings::cassette_gift_ui_hidden_for_wp_user( $wp_user_id );
        ?>
        <div class="epc-profile-box epc-notes-box">
            <div class="epc-profile-box-header epc-notes-box-header">
                <span class="dashicons dashicons-edit"></span>
                <?php esc_html_e( 'Σημειώσεις Admin', 'epappous-club' ); ?>
            </div>
            <div class="epc-profile-box-body">

                <?php if ( $show_cassette ) :
                    $cassette      = get_user_meta( $wp_user_id, self::USER_META_CASSETTE, true );
                    $cassette      = ( $cassette === 'yes' ) ? 'yes' : 'no';
                    $cassette_date = get_user_meta( $wp_user_id, self::USER_META_CASSETTE_DATE, true );
                    $cassette_date = is_string( $cassette_date ) ? $cassette_date : '';
                    $cassette_audit = self::get_cassette_audit_for_display( $wp_user_id );
                    ?>
                <div class="epc-cassette-gift-row">
                    <label class="epc-cassette-gift-label"><?php esc_html_e( 'Έχει πάρει δώρο κασετίνα;', 'epappous-club' ); ?></label>
                    <div class="epc-cassette-gift-controls">
                        <label class="epc-cassette-option">
                            <input type="radio" name="epc_cassette_received" value="no" class="epc-cassette-received-input" <?php checked( $cassette, 'no' ); ?> />
                            <?php esc_html_e( 'ΟΧΙ', 'epappous-club' ); ?>
                        </label>
                        <label class="epc-cassette-option">
                            <input type="radio" name="epc_cassette_received" value="yes" class="epc-cassette-received-input" <?php checked( $cassette, 'yes' ); ?> />
                            <?php esc_html_e( 'ΝΑΙ', 'epappous-club' ); ?>
                        </label>
                        <label class="epc-cassette-date-wrap">
                            <span class="epc-cassette-date-label"><?php esc_html_e( 'Ημερομηνία που πήρε το δώρο', 'epappous-club' ); ?></span>
                            <input type="date" class="epc-cassette-date-input" name="epc_cassette_gift_date"
                                   value="<?php echo esc_attr( $cassette_date ); ?>"
                                <?php disabled( $cassette !== 'yes' ); ?> />
                        </label>
                        <button type="button" class="button button-primary epc-save-cassette-btn"
                                data-user-id="<?php echo (int) $wp_user_id; ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            <?php esc_html_e( 'Αποθήκευση', 'epappous-club' ); ?>
                        </button>
                    </div>
                    <p class="epc-cassette-audit description" style="<?php echo $cassette_audit ? '' : 'display:none;'; ?>">
                        <?php
                        if ( $cassette_audit ) {
                            printf(
                                /* translators: 1: admin display name, 2: datetime */
                                esc_html__( 'Τελευταία καταχώρηση: %1$s — %2$s', 'epappous-club' ),
                                esc_html( $cassette_audit['editor_name'] ),
                                esc_html( $cassette_audit['edited_at'] )
                            );
                        }
                        ?>
                    </p>
                    <span class="epc-cassette-saved-msg" style="display:none;"></span>
                </div>
                <?php endif; ?>

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
                                <?php
                                $note_updated = ! empty( $note['updated_at'] ) ? $note['updated_at'] : null;
                                ?>
                                <div class="epc-note-item" data-note-id="<?php echo (int) $note['id']; ?>">
                                    <div class="epc-note-date">
                                        <?php echo esc_html( date_i18n( 'd.m.y', strtotime( $note['created_at'] ) ) ); ?>
                                        <br /><span><?php echo esc_html( date_i18n( 'H:i', strtotime( $note['created_at'] ) ) ); ?></span>
                                        <?php if ( $note_updated ) : ?>
                                            <br /><span class="epc-note-updated-hint"><?php esc_html_e( 'επεξ.', 'epappous-club' ); ?> <?php echo esc_html( date_i18n( 'd.m.y H:i', strtotime( $note_updated ) ) ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="epc-note-main">
                                        <div class="epc-note-view">
                                            <div class="epc-note-body"><?php echo esc_html( $note['note'] ); ?></div>
                                            <div class="epc-note-actions">
                                                <button type="button" class="button-link epc-edit-note-btn"><?php esc_html_e( 'Επεξεργασία', 'epappous-club' ); ?></button>
                                                <button type="button" class="button-link epc-delete-note-btn"
                                                        data-note-id="<?php echo (int) $note['id']; ?>"
                                                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                                    <?php esc_html_e( 'Διαγραφή', 'epappous-club' ); ?>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="epc-note-edit" style="display:none;">
                                            <textarea class="epc-note-edit-text" rows="3"><?php echo esc_textarea( $note['note'] ); ?></textarea>
                                            <div class="epc-note-edit-actions">
                                                <button type="button" class="button button-primary epc-save-note-btn"
                                                        data-note-id="<?php echo (int) $note['id']; ?>"
                                                        data-user-id="<?php echo (int) $wp_user_id; ?>"
                                                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                                    <?php esc_html_e( 'Αποθήκευση', 'epappous-club' ); ?>
                                                </button>
                                                <button type="button" class="button epc-cancel-note-edit-btn"><?php esc_html_e( 'Ακύρωση', 'epappous-club' ); ?></button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="epc-note-meta">
                                        <small><?php echo esc_html( $note['author_name'] ?? '' ); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    // ── AJAX ──

    public function ajax_add_note() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $user_id   = (int) ( $_POST['user_id'] ?? 0 );
        $note_text = sanitize_textarea_field( $_POST['note'] ?? '' );

        if ( $user_id < 1 || empty( $note_text ) ) {
            wp_send_json_error( 'Missing data' );
        }

        if ( ! EPC_Capabilities::current_user_can_edit_wp_user( $user_id ) ) {
            wp_send_json_error( 'Forbidden', 403 );
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
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $note_id = (int) ( $_POST['note_id'] ?? 0 );
        if ( $note_id < 1 ) {
            wp_send_json_error( 'Missing note_id' );
        }

        global $wpdb;
        $owner = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}epc_member_notes WHERE id = %d LIMIT 1",
                $note_id
            )
        );
        if ( $owner < 1 ) {
            wp_send_json_error( 'Not found' );
        }
        if ( ! EPC_Capabilities::current_user_can_edit_wp_user( $owner ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $wpdb->delete(
            "{$wpdb->prefix}epc_member_notes",
            [ 'id' => $note_id ],
            [ '%d' ]
        );

        wp_send_json_success();
    }

    /**
     * Update an existing admin note (same user profile).
     */
    public function ajax_update_note() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $note_id   = (int) ( $_POST['note_id'] ?? 0 );
        $user_id   = (int) ( $_POST['user_id'] ?? 0 );
        $note_text = sanitize_textarea_field( $_POST['note'] ?? '' );

        if ( $note_id < 1 || $user_id < 1 || $note_text === '' ) {
            wp_send_json_error( 'Missing data' );
        }

        if ( ! EPC_Capabilities::current_user_can_edit_wp_user( $user_id ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        global $wpdb;
        $owns = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}epc_member_notes WHERE id = %d AND user_id = %d",
                $note_id,
                $user_id
            )
        );
        if ( $owns < 1 ) {
            wp_send_json_error( 'Not found' );
        }

        $wpdb->update(
            "{$wpdb->prefix}epc_member_notes",
            [
                'note'       => $note_text,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $note_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [
            'note'        => $note_text,
            'updated_str' => date_i18n( 'd.m.y H:i' ),
        ] );
    }

    /**
     * Save "cassette gift" flag and date on the WordPress user (shown on orders when ΝΑΙ).
     */
    public function ajax_save_cassette_gift() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        $received = sanitize_text_field( $_POST['received'] ?? 'no' );
        $date_raw = isset( $_POST['gift_date'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_date'] ) ) : '';

        if ( $user_id < 1 ) {
            wp_send_json_error( __( 'Λείπει χρήστης.', 'epappous-club' ) );
        }

        if ( ! EPC_Capabilities::current_user_can_edit_wp_user( $user_id ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        if ( EPC_Settings::cassette_gift_ui_hidden_for_wp_user( $user_id ) ) {
            wp_send_json_error( __( 'Η κασσετίνα δώρο δεν ισχύει για αυτόν τον τύπο πελάτη.', 'epappous-club' ) );
        }

        if ( ! in_array( $received, [ 'yes', 'no' ], true ) ) {
            $received = 'no';
        }

        update_user_meta( $user_id, self::USER_META_CASSETTE, $received );

        if ( $received === 'yes' ) {
            if ( $date_raw !== '' ) {
                $d = \DateTime::createFromFormat( 'Y-m-d', $date_raw );
                if ( $d instanceof \DateTime ) {
                    update_user_meta( $user_id, self::USER_META_CASSETTE_DATE, $d->format( 'Y-m-d' ) );
                }
            } else {
                delete_user_meta( $user_id, self::USER_META_CASSETTE_DATE );
            }
        } else {
            delete_user_meta( $user_id, self::USER_META_CASSETTE_DATE );
        }

        $editor_id = get_current_user_id();
        update_user_meta( $user_id, self::USER_META_CASSETTE_EDITED_BY, $editor_id );
        update_user_meta( $user_id, self::USER_META_CASSETTE_EDITED_AT, current_time( 'mysql' ) );

        $editor = wp_get_current_user();
        wp_send_json_success(
            [
                'editor_name' => $editor->display_name,
                'edited_at'   => date_i18n( 'd/m/Y H:i' ),
                'audit_text'  => sprintf(
                    /* translators: 1: admin display name, 2: datetime */
                    __( 'Τελευταία καταχώρηση: %1$s — %2$s', 'epappous-club' ),
                    $editor->display_name,
                    date_i18n( 'd/m/Y H:i' )
                ),
            ]
        );
    }

    /**
     * Toggle membership status (active/inactive) or enroll a non-member.
     */
    public function ajax_toggle_membership() {
        check_ajax_referer( 'epc_admin_nonce', 'nonce' );
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        $member_id = (int) ( $_POST['member_id'] ?? 0 );
        $user_id   = (int) ( $_POST['user_id'] ?? 0 );
        $enable    = ! empty( $_POST['enable'] );

        if ( $member_id > 0 ) {
            $mid_user = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}epc_members WHERE id = %d LIMIT 1",
                    $member_id
                )
            );
            if ( $mid_user > 0 && ! EPC_Capabilities::current_user_can_edit_wp_user( $mid_user ) ) {
                wp_send_json_error( 'Forbidden', 403 );
            }

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
            if ( ! EPC_Capabilities::current_user_can_edit_wp_user( $user_id ) ) {
                wp_send_json_error( 'Forbidden', 403 );
            }

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

            EPC_Member_Sync::after_club_registration( $new_member_id, $wp_user->user_email );

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
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
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
                "SELECT id, points, user_id FROM {$wpdb->prefix}epc_members WHERE id = %d",
                $member_id
            )
        );

        if ( ! $member ) {
            wp_send_json_error( __( 'Δεν βρέθηκε μέλος', 'epappous-club' ) );
        }

        $wp_uid = (int) $member->user_id;
        if ( $wp_uid > 0 && ! EPC_Capabilities::current_user_can_edit_wp_user( $wp_uid ) ) {
            wp_send_json_error( 'Forbidden', 403 );
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
        if ( ! EPC_Capabilities::current_user_can_manage_club() ) {
            wp_send_json_error( 'Unauthorized', 403 );
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
