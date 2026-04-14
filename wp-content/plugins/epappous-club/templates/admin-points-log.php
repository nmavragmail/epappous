<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// Search parameters
$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
$page      = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page  = 30;
$offset    = ( $page - 1 ) * $per_page;

$where  = '1=1';
$params = [];

if ( ! empty( $search ) ) {
    $like = '%' . $wpdb->esc_like( $search ) . '%';
    if ( is_numeric( $search ) ) {
        $where .= $wpdb->prepare(
            " AND (m.id = %d OR m.user_id = %d OR m.first_name LIKE %s OR m.last_name LIKE %s OR m.email LIKE %s)",
            (int) $search,
            (int) $search,
            $like,
            $like,
            $like
        );
    } else {
        $where .= $wpdb->prepare(
            " AND (m.first_name LIKE %s OR m.last_name LIKE %s OR m.email LIKE %s)",
            $like,
            $like,
            $like
        );
    }
}

$total = (int) $wpdb->get_var(
    "SELECT COUNT(*)
     FROM {$wpdb->prefix}epc_points_log pl
     LEFT JOIN {$wpdb->prefix}epc_members m ON pl.member_id = m.id
     WHERE {$where}" // phpcs:ignore
);

$logs = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT pl.*,
                m.first_name, m.last_name, m.email, m.points AS current_points,
                m.tier, m.referral_code,
                u.display_name AS admin_name
         FROM {$wpdb->prefix}epc_points_log pl
         LEFT JOIN {$wpdb->prefix}epc_members m ON pl.member_id = m.id
         LEFT JOIN {$wpdb->users} u ON pl.admin_user_id = u.ID
         WHERE {$where}
         ORDER BY pl.created_at DESC
         LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ),
    ARRAY_A
); // phpcs:ignore

$total_pages = ceil( $total / $per_page );

$reason_labels = [
    'birthday_bonus'             => [ 'label' => 'Μπόνους Γενεθλίων',                      'icon' => 'dashicons-cake',          'color' => '#ec4899' ],
    'referral_bonus_referrer'    => [ 'label' => 'Referral — Ανταμοιβή Referrer',           'icon' => 'dashicons-share',         'color' => '#10b981' ],
    'referral_bonus_referred'    => [ 'label' => 'Referral — Ανταμοιβή Νέου Μέλους',       'icon' => 'dashicons-share-alt',     'color' => '#06b6d4' ],
    'referral_purchase_referrer' => [ 'label' => 'Referral Αγοράς — Ανταμοιβή Referrer',   'icon' => 'dashicons-share',         'color' => '#10b981' ],
    'referral_purchase_referred' => [ 'label' => 'Referral Αγοράς — Ανταμοιβή Αγοραστή',  'icon' => 'dashicons-share-alt',     'color' => '#06b6d4' ],
    'gift_redemption'            => [ 'label' => 'Εξαργύρωση Δώρου',                       'icon' => 'dashicons-cart',           'color' => '#f59e0b' ],
    'order_earning'              => [ 'label' => 'Πόντοι από Παραγγελία',                   'icon' => 'dashicons-store',         'color' => '#3b82f6' ],
    'manual_adjustment'          => [ 'label' => 'Χειροκίνητη Προσαρμογή',                  'icon' => 'dashicons-admin-tools',   'color' => '#6b7280' ],
    'points_expiry'              => [ 'label' => 'Λήξη Πόντων',                             'icon' => 'dashicons-clock',         'color' => '#ef4444' ],
    'signup_bonus'               => [ 'label' => 'Μπόνους Εγγραφής',                        'icon' => 'dashicons-admin-users',   'color' => '#8b5cf6' ],
    'checkout_redemption'        => [ 'label' => 'Εξαργύρωση στο Checkout',                'icon' => 'dashicons-money-alt',     'color' => '#dc2626' ],
];
?>
<div class="wrap epc-wrap">
    <div class="epc-header">
        <h1>
            <span class="dashicons dashicons-list-view"></span>
            <?php esc_html_e( 'Ιστορικό Πόντων', 'epappous-club' ); ?>
        </h1>
        <div style="display:flex;align-items:center;gap:12px;">
            <span class="epc-header-count">
                <?php printf( esc_html__( '%s εγγραφές', 'epappous-club' ), number_format( $total ) ); ?>
            </span>
            <button type="button" class="button" id="epc-log-adjust-btn" style="background:rgba(255,255,255,0.2);color:#fff;border-color:rgba(255,255,255,0.3);">
                <span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
                <?php esc_html_e( 'Προσθήκη / Αφαίρεση Πόντων', 'epappous-club' ); ?>
            </button>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="epc-search-bar">
        <form method="get" action="">
            <input type="hidden" name="page" value="epc-points-log" />
            <div class="epc-search-group">
                <span class="dashicons dashicons-search"></span>
                <input type="text" name="s" id="epc-log-search"
                       value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Αναζήτηση με ID, όνομα ή email...', 'epappous-club' ); ?>"
                       class="epc-search-input" />
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Αναζήτηση', 'epappous-club' ); ?></button>
                <?php if ( ! empty( $search ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-points-log' ) ); ?>" class="button">
                        <?php esc_html_e( 'Καθαρισμός', 'epappous-club' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ( ! empty( $search ) ) : ?>
        <p class="epc-search-result-text">
            <?php printf(
                esc_html__( 'Βρέθηκαν %d αποτελέσματα για "%s"', 'epappous-club' ),
                $total,
                esc_html( $search )
            ); ?>
        </p>
    <?php endif; ?>

    <?php if ( empty( $logs ) ) : ?>
        <div class="epc-empty-state">
            <span class="dashicons dashicons-list-view"></span>
            <h3><?php esc_html_e( 'Δεν βρέθηκαν εγγραφές πόντων', 'epappous-club' ); ?></h3>
            <?php if ( ! empty( $search ) ) : ?>
                <p><?php esc_html_e( 'Δοκιμάστε διαφορετικό κριτήριο αναζήτησης.', 'epappous-club' ); ?></p>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped epc-table epc-points-table">
            <thead>
                <tr>
                    <th class="column-id"><?php esc_html_e( 'ID', 'epappous-club' ); ?></th>
                    <th class="column-member"><?php esc_html_e( 'Μέλος', 'epappous-club' ); ?></th>
                    <th class="column-points"><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></th>
                    <th class="column-reason"><?php esc_html_e( 'Λόγος', 'epappous-club' ); ?></th>
                    <th class="column-ref"><?php esc_html_e( 'Αναφορά', 'epappous-club' ); ?></th>
                    <th class="column-admin"><?php esc_html_e( 'Από', 'epappous-club' ); ?></th>
                    <th class="column-date"><?php esc_html_e( 'Ημερομηνία', 'epappous-club' ); ?></th>
                    <th class="column-debug"><?php esc_html_e( 'Debug', 'epappous-club' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log ) :
                    $reason_key  = $log['reason'];
                    $reason_info = $reason_labels[ $reason_key ] ?? [
                        'label' => $reason_key,
                        'icon'  => 'dashicons-info',
                        'color' => '#6b7280',
                    ];
                    $pts = (int) $log['points'];
                ?>
                    <tr>
                        <td class="column-id"><?php echo (int) $log['id']; ?></td>
                        <td class="column-member">
                            <?php if ( $log['first_name'] ) : ?>
                                <strong><?php echo esc_html( $log['first_name'] . ' ' . $log['last_name'] ); ?></strong>
                                <br /><small class="epc-member-email"><?php echo esc_html( $log['email'] ); ?></small>
                                <br /><small class="epc-member-id">ID: <?php echo (int) $log['member_id']; ?></small>
                            <?php else : ?>
                                <span class="epc-deleted-member">
                                    <?php printf( esc_html__( 'Μέλος #%d (διαγράφηκε)', 'epappous-club' ), (int) $log['member_id'] ); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="column-points">
                            <span class="epc-points-value <?php echo $pts >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $pts >= 0 ? '+' . number_format( $pts ) : number_format( $pts ); ?>
                            </span>
                        </td>
                        <td class="column-reason">
                            <span class="epc-reason-badge" style="--reason-color: <?php echo esc_attr( $reason_info['color'] ); ?>">
                                <span class="dashicons <?php echo esc_attr( $reason_info['icon'] ); ?>"></span>
                                <?php echo esc_html( $reason_info['label'] ); ?>
                            </span>
                        </td>
                        <td class="column-ref">
                            <?php if ( ! empty( $log['reference_type'] ) ) : ?>
                                <code><?php echo esc_html( $log['reference_type'] ); ?>#<?php echo (int) $log['reference_id']; ?></code>
                            <?php else : ?>
                                <span class="epc-na">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-admin">
                            <?php if ( ! empty( $log['admin_name'] ) ) : ?>
                                <small><?php echo esc_html( $log['admin_name'] ); ?></small>
                            <?php else : ?>
                                <span class="epc-na">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-date">
                            <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $log['created_at'] ) ) ); ?>
                            <br /><small><?php echo esc_html( date_i18n( 'H:i:s', strtotime( $log['created_at'] ) ) ); ?></small>
                        </td>
                        <td class="column-debug">
                            <button type="button" class="button epc-debug-btn"
                                    data-log='<?php echo esc_attr( wp_json_encode( [
                                        'id'             => (int) $log['id'],
                                        'member_id'      => (int) $log['member_id'],
                                        'member_name'    => trim( ( $log['first_name'] ?? '' ) . ' ' . ( $log['last_name'] ?? '' ) ),
                                        'member_email'   => $log['email'] ?? '',
                                        'member_tier'    => $log['tier'] ?? '',
                                        'member_points'  => (int) ( $log['current_points'] ?? 0 ),
                                        'referral_code'  => $log['referral_code'] ?? '',
                                        'points'         => $pts,
                                        'reason'         => $reason_key,
                                        'reason_label'   => $reason_info['label'],
                                        'reference_type' => $log['reference_type'] ?? '',
                                        'reference_id'   => (int) ( $log['reference_id'] ?? 0 ),
                                        'created_at'     => $log['created_at'],
                                    ] ) ); ?>'
                                    title="<?php esc_attr_e( 'Γιατί πήρε αυτούς τους πόντους;', 'epappous-club' ); ?>">
                                <span class="dashicons dashicons-info-outline"></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $pagination_args = [ 'paged' => '%#%' ];
                    if ( ! empty( $search ) ) {
                        $pagination_args['s'] = $search;
                    }
                    echo paginate_links( [
                        'base'    => add_query_arg( $pagination_args ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $total_pages,
                    ] );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Points Adjustment Modal -->
<div id="epc-log-adjust-modal" class="epc-modal" style="display:none;">
    <div class="epc-modal-overlay"></div>
    <div class="epc-modal-content" style="max-width:520px;">
        <div class="epc-modal-header">
            <h2>
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e( 'Προσθήκη / Αφαίρεση Πόντων', 'epappous-club' ); ?>
            </h2>
            <button type="button" class="epc-modal-close">&times;</button>
        </div>
        <div class="epc-modal-body">
            <div class="epc-field-row" style="margin-bottom:16px;">
                <label><?php esc_html_e( 'Αναζήτηση Μέλους', 'epappous-club' ); ?></label>
                <input type="text" id="epc-log-member-search" placeholder="<?php esc_attr_e( 'Πληκτρολόγησε όνομα ή email...', 'epappous-club' ); ?>" autocomplete="off" />
                <div id="epc-log-member-results" style="display:none;border:1px solid #e5e7eb;border-radius:6px;max-height:180px;overflow-y:auto;margin-top:4px;background:#fff;"></div>
                <input type="hidden" id="epc-log-member-id" value="" />
                <div id="epc-log-member-selected" style="display:none;margin-top:8px;padding:8px 12px;background:#f3f4f6;border-radius:6px;font-size:13px;"></div>
            </div>
            <div class="epc-field-row-inline" style="margin-bottom:16px;">
                <div>
                    <label><?php esc_html_e( 'Ενέργεια', 'epappous-club' ); ?></label>
                    <select id="epc-log-adjust-type">
                        <option value="add">+ <?php esc_html_e( 'Προσθήκη', 'epappous-club' ); ?></option>
                        <option value="remove">− <?php esc_html_e( 'Αφαίρεση', 'epappous-club' ); ?></option>
                    </select>
                </div>
                <div>
                    <label><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></label>
                    <input type="number" id="epc-log-adjust-amount" min="1" placeholder="0" />
                </div>
            </div>
            <div class="epc-field-row" style="margin-bottom:0;">
                <label><?php esc_html_e( 'Λόγος', 'epappous-club' ); ?></label>
                <input type="text" id="epc-log-adjust-reason" placeholder="<?php esc_attr_e( 'π.χ. Επιστροφή πόντων, Μπόνους κ.λπ. (προαιρετικό)', 'epappous-club' ); ?>" />
            </div>
        </div>
        <div class="epc-modal-footer">
            <button type="button" class="button epc-modal-close-btn"><?php esc_html_e( 'Ακύρωση', 'epappous-club' ); ?></button>
            <button type="button" class="button button-primary" id="epc-log-adjust-submit"><?php esc_html_e( 'Εφαρμογή', 'epappous-club' ); ?></button>
        </div>
    </div>
</div>

<!-- Debug Modal -->
<div id="epc-debug-modal" class="epc-modal" style="display:none;">
    <div class="epc-modal-overlay"></div>
    <div class="epc-modal-content epc-debug-modal-content">
        <div class="epc-modal-header">
            <h2>
                <span class="dashicons dashicons-info-outline"></span>
                <?php esc_html_e( 'Debug — Γιατί δόθηκαν αυτοί οι πόντοι;', 'epappous-club' ); ?>
            </h2>
            <button type="button" class="epc-modal-close">&times;</button>
        </div>
        <div class="epc-modal-body">
            <div id="epc-debug-content"></div>
        </div>
        <div class="epc-modal-footer">
            <button type="button" class="button epc-modal-close-btn"><?php esc_html_e( 'Κλείσιμο', 'epappous-club' ); ?></button>
        </div>
    </div>
</div>
