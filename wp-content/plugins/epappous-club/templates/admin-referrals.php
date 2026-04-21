<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals" );
$referrals = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT r.*,
                m1.first_name AS referrer_first, m1.last_name AS referrer_last, m1.email AS referrer_email, m1.referral_code,
                m2.first_name AS referred_first, m2.last_name AS referred_last, m2.email AS referred_email_addr
         FROM {$wpdb->prefix}epc_referrals r
         LEFT JOIN {$wpdb->prefix}epc_members m1 ON r.referrer_member_id = m1.id
         LEFT JOIN {$wpdb->prefix}epc_members m2 ON r.referred_member_id = m2.id
         ORDER BY r.created_at DESC
         LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ),
    ARRAY_A
);

$total_pages = ceil( $total / $per_page );

// --- Pending / converted referral clicks --------------------------------
$click_per_page = 50;
$click_page     = max( 1, (int) ( $_GET['cpaged'] ?? 1 ) ); // phpcs:ignore
$click_offset   = ( $click_page - 1 ) * $click_per_page;
$click_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referral_clicks" );
$click_pages    = $click_total > 0 ? (int) ceil( $click_total / $click_per_page ) : 0;

$clicks = [];
if ( $click_total > 0 ) {
    $clicks = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.*,
                    m1.first_name AS referrer_first, m1.last_name AS referrer_last, m1.email AS referrer_email, m1.referral_code,
                    m2.first_name AS converted_first, m2.last_name AS converted_last, m2.email AS converted_email
               FROM {$wpdb->prefix}epc_referral_clicks c
          LEFT JOIN {$wpdb->prefix}epc_members m1 ON c.referrer_member_id = m1.id
          LEFT JOIN {$wpdb->prefix}epc_members m2 ON c.converted_member_id = m2.id
           ORDER BY c.first_clicked_at DESC
              LIMIT %d OFFSET %d",
            $click_per_page,
            $click_offset
        ),
        ARRAY_A
    );
}

$cookie_days = (int) EPC_Referral::cookie_days();
$now_ts      = (int) current_time( 'U' );
?>
<div class="wrap epc-wrap">
    <style>
        /* Safety: if a legacy top-level reconcile notice is still injected, hide it. */
        .epc-wrap > .notice.notice-info:not(.epc-retrospective-result) {
            display: none !important;
        }
    </style>
    <div class="epc-header">
        <h1>
            <span class="dashicons dashicons-share"></span>
            <?php esc_html_e( 'Referrals', 'epappous-club' ); ?>
        </h1>
    </div>

    <!-- Explainer -->
    <div class="epc-info-box epc-info-box-large">
        <h3><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'Πώς λειτουργεί το Referral;', 'epappous-club' ); ?></h3>
        <ol>
            <li><?php esc_html_e( 'Κάθε μέλος λαμβάνει ένα μοναδικό κωδικό referral (π.χ. PAPPOU-A3X9).', 'epappous-club' ); ?></li>
            <li><?php esc_html_e( 'Μοιράζεται τον κωδικό ή ένα σύνδεσμο (π.χ. example.com/?ref=PAPPOU-A3X9) σε φίλους.', 'epappous-club' ); ?></li>
            <li><?php esc_html_e( 'Ο σύνδεσμος αποθηκεύει ένα cookie στον browser του φίλου.', 'epappous-club' ); ?></li>
            <li>
                <?php esc_html_e( 'Για να δοθεί η ανταμοιβή πρέπει να ισχύουν ΚΑΙ οι δύο προϋποθέσεις (σε όποια σειρά):', 'epappous-club' ); ?>
                <ul>
                    <li><strong><?php esc_html_e( 'Εγγραφή μέλους:', 'epappous-club' ); ?></strong> <?php esc_html_e( 'Ο φίλος γίνεται μέλος του Pappou Club.', 'epappous-club' ); ?></li>
                    <li><strong><?php esc_html_e( 'Αγορά:', 'epappous-club' ); ?></strong> <?php esc_html_e( 'Ο φίλος ολοκληρώνει μια παραγγελία στο κατάστημα.', 'epappous-club' ); ?></li>
                </ul>
            </li>
            <li><?php esc_html_e( 'Αν ισχύει μόνο η μία προϋπόθεση, η περίπτωση καταγράφεται ως εκκρεμής στο log και η ανταμοιβή δίνεται αυτόματα μόλις ικανοποιηθεί και η δεύτερη.', 'epappous-club' ); ?></li>
            <li><?php esc_html_e( 'Όταν ολοκληρωθεί, και οι δύο (referrer & φίλος) κερδίζουν ανταμοιβή σύμφωνα με τις ρυθμίσεις.', 'epappous-club' ); ?></li>
        </ol>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings&tab=referral' ) ); ?>" class="button button-secondary">
                <?php esc_html_e( 'Ρυθμίσεις Referral →', 'epappous-club' ); ?>
            </a>
        </p>
    </div>

    <?php if ( empty( $referrals ) ) : ?>
        <div class="epc-empty-state">
            <span class="dashicons dashicons-share"></span>
            <h3><?php esc_html_e( 'Δεν υπάρχουν referrals ακόμα', 'epappous-club' ); ?></h3>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped epc-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Referrer', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Referred', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Τύπος', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Ανταμοιβή', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Ημερομηνία', 'epappous-club' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $referrals as $ref ) : ?>
                    <tr>
                        <td><?php echo (int) $ref['id']; ?></td>
                        <td>
                            <strong><?php echo esc_html( $ref['referrer_first'] . ' ' . $ref['referrer_last'] ); ?></strong>
                            <br /><small><?php echo esc_html( $ref['referrer_email'] ); ?></small>
                            <br /><code><?php echo esc_html( $ref['referral_code'] ); ?></code>
                        </td>
                        <td>
                            <?php if ( $ref['referred_first'] ) : ?>
                                <strong><?php echo esc_html( $ref['referred_first'] . ' ' . $ref['referred_last'] ); ?></strong>
                                <br /><small><?php echo esc_html( $ref['referred_email_addr'] ); ?></small>
                            <?php else : ?>
                                <small><?php echo esc_html( $ref['referred_email'] ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="epc-badge epc-badge-<?php echo esc_attr( $ref['type'] ); ?>">
                                <?php echo $ref['type'] === 'membership'
                                    ? esc_html__( 'Εγγραφή', 'epappous-club' )
                                    : esc_html__( 'Αγορά', 'epappous-club' ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( number_format( $ref['reward_points'] ) ); ?></td>
                        <td>
                            <span class="epc-status epc-status-<?php echo esc_attr( $ref['status'] ); ?>">
                                <?php
                                $statuses = [
                                    'pending'   => __( 'Εκκρεμεί', 'epappous-club' ),
                                    'completed' => __( 'Ολοκληρωμένο', 'epappous-club' ),
                                    'cancelled' => __( 'Ακυρωμένο', 'epappous-club' ),
                                ];
                                echo esc_html( $statuses[ $ref['status'] ] ?? $ref['status'] );
                                ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( $ref['reward_given'] ) : ?>
                                <span class="epc-status epc-status-completed"><?php esc_html_e( 'Δόθηκε', 'epappous-club' ); ?></span>
                            <?php else : ?>
                                <span class="epc-status epc-status-pending"><?php esc_html_e( 'Εκκρεμεί', 'epappous-club' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $ref['created_at'] ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $total_pages,
                    ] );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <h2 style="margin-top:2em;">
        <span class="dashicons dashicons-visibility"></span>
        <?php esc_html_e( 'Clicks σε referral links', 'epappous-club' ); ?>
    </h2>
    <p class="description">
        <?php
        printf(
            /* translators: %d: cookie lifetime in days */
            esc_html__( 'Καταγράφονται οι επισκέψεις μέσω ?ref= και διατηρούνται για %d ημέρες.', 'epappous-club' ),
            (int) $cookie_days
        );
        ?>
    </p>

    <?php if ( empty( $clicks ) ) : ?>
        <div class="epc-empty-state" style="padding:1.5em;">
            <span class="dashicons dashicons-visibility"></span>
            <p style="margin:0;"><?php esc_html_e( 'Δεν έχει καταγραφεί ακόμα κανένα click σε referral link.', 'epappous-club' ); ?></p>
        </div>
    <?php else : ?>
        <p class="description" style="margin-bottom:.5em;">
            <?php esc_html_e( 'Η ανταμοιβή δίνεται μόνο όταν ο φίλος γίνει μέλος ΚΑΙ κάνει αγορά (σε όποια σειρά).', 'epappous-club' ); ?>
        </p>
        <table class="wp-list-table widefat fixed striped epc-table epc-clicks-table">
            <thead>
                <tr>
                    <th style="width:60px;"><?php esc_html_e( 'ID', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Referrer', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Πρώτο click', 'epappous-club' ); ?></th>
                    <th style="width:70px;"><?php esc_html_e( 'Clicks', 'epappous-club' ); ?></th>
                    <th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Debug', 'epappous-club' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $clicks as $click ) :
                    $first_ts   = strtotime( $click['first_clicked_at'] );
                    $last_ts    = strtotime( $click['last_clicked_at'] );
                    $expires_ts = $first_ts + ( DAY_IN_SECONDS * $cookie_days );

                    $has_member   = ! empty( $click['converted_member_id'] );
                    $has_purchase = ! empty( $click['purchased_order_id'] );
                    $is_rewarded  = ! empty( $click['rewarded_at'] );

                    $converted_name = trim( ( $click['converted_first'] ?? '' ) . ' ' . ( $click['converted_last'] ?? '' ) );
                    $order          = $has_purchase ? wc_get_order( (int) $click['purchased_order_id'] ) : null;
                    ?>
                    <tr>
                        <td>#<?php echo (int) $click['id']; ?></td>
                        <td>
                            <?php if ( $click['referrer_first'] || $click['referrer_last'] ) : ?>
                                <strong><?php echo esc_html( trim( $click['referrer_first'] . ' ' . $click['referrer_last'] ) ); ?></strong>
                                <br /><small><?php echo esc_html( $click['referrer_email'] ); ?></small>
                            <?php endif; ?>
                            <br /><code><?php echo esc_html( $click['ref_code'] ); ?></code>
                        </td>
                        <td>
                            <?php echo esc_html( date_i18n( 'd/m/Y H:i', $first_ts ) ); ?>
                            <br />
                            <small style="color:#646970;">
                                <?php
                                printf(
                                    /* translators: %s: human-readable time difference */
                                    esc_html__( 'πριν %s', 'epappous-club' ),
                                    esc_html( human_time_diff( $first_ts, $now_ts ) )
                                );
                                ?>
                            </small>
                        </td>
                        <td><?php echo (int) $click['click_count']; ?></td>
                        <td>
                            <?php if ( $is_rewarded ) : ?>
                                <span class="epc-status epc-status-completed">
                                    <?php esc_html_e( 'Ολοκληρώθηκε — δόθηκε ανταμοιβή', 'epappous-club' ); ?>
                                </span>
                                <br /><small>
                                    <?php
                                    printf(
                                        /* translators: %s: date */
                                        esc_html__( 'Ανταμοιβή: %s', 'epappous-club' ),
                                        esc_html( date_i18n( 'd/m/Y H:i', strtotime( $click['rewarded_at'] ) ) )
                                    );
                                    ?>
                                </small>
                            <?php elseif ( $has_member && $has_purchase ) : ?>
                                <span class="epc-status epc-status-pending">
                                    <?php esc_html_e( 'Αναμονή ανταμοιβής', 'epappous-club' ); ?>
                                </span>
                            <?php elseif ( $has_member && ! $has_purchase ) : ?>
                                <span class="epc-status epc-status-pending">
                                    <?php esc_html_e( 'Έγινε μέλος — δεν έχει αγοράσει ακόμα', 'epappous-club' ); ?>
                                </span>
                                <?php if ( $converted_name ) : ?>
                                    <br /><small><?php echo esc_html( $converted_name ); ?></small>
                                <?php endif; ?>
                            <?php elseif ( $has_purchase && ! $has_member ) : ?>
                                <span class="epc-status epc-status-pending">
                                    <?php esc_html_e( 'Έκανε αγορά — δεν είναι μέλος ακόμα', 'epappous-club' ); ?>
                                </span>
                                <?php if ( $order ) : ?>
                                    <br /><small>
                                        <?php
                                        printf(
                                            /* translators: 1: order id, 2: total */
                                            esc_html__( 'Παραγγελία #%1$d · %2$s', 'epappous-club' ),
                                            (int) $order->get_id(),
                                            wp_kses_post( wc_price( (float) $click['purchase_total'] ) )
                                        );
                                        ?>
                                    </small>
                                <?php endif; ?>
                            <?php else :
                                $days_left = (int) ceil( ( $expires_ts - $now_ts ) / DAY_IN_SECONDS );
                                if ( $days_left > 0 ) : ?>
                                    <span class="epc-status epc-status-pending">
                                        <?php
                                        printf(
                                            esc_html__( 'Λήγει σε %s', 'epappous-club' ),
                                            esc_html( sprintf( _n( '%d ημέρα', '%d ημέρες', $days_left, 'epappous-club' ), $days_left ) )
                                        );
                                        ?>
                                    </span>
                                <?php else : ?>
                                    <span class="epc-status epc-status-cancelled">
                                        <?php esc_html_e( 'Έληξε', 'epappous-club' ); ?>
                                    </span>
                                <?php endif;
                            endif; ?>
                        </td>
                        <td>
                            <button type="button" class="button button-small epc-debug-toggle" data-target="epc-debug-<?php echo (int) $click['id']; ?>">
                                <?php esc_html_e( 'Debug', 'epappous-club' ); ?>
                            </button>
                        </td>
                    </tr>
                    <tr id="epc-debug-<?php echo (int) $click['id']; ?>" class="epc-debug-row" style="display:none; background:#f6f7f7;">
                        <td colspan="6">
                            <?php
                            $member_user_id = 0;
                            $member_user    = null;
                            $member_row     = null;
                            if ( $has_member ) {
                                $member_row = $wpdb->get_row(
                                    $wpdb->prepare(
                                        "SELECT * FROM {$wpdb->prefix}epc_members WHERE id = %d LIMIT 1",
                                        (int) $click['converted_member_id']
                                    ),
                                    ARRAY_A
                                );
                                if ( $member_row && ! empty( $member_row['user_id'] ) ) {
                                    $member_user_id = (int) $member_row['user_id'];
                                    $member_user    = get_userdata( $member_user_id );
                                }
                            }
                            $referrer_row = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT * FROM {$wpdb->prefix}epc_members WHERE id = %d LIMIT 1",
                                    (int) $click['referrer_member_id']
                                ),
                                ARRAY_A
                            );
                            ?>
                            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1em; padding:.6em 0;">
                                <div>
                                    <h4 style="margin:0 0 .3em;"><?php esc_html_e( 'Click', 'epappous-club' ); ?></h4>
                                    <ul style="margin:0; line-height:1.7;">
                                        <li><strong>ID:</strong> #<?php echo (int) $click['id']; ?></li>
                                        <li><strong><?php esc_html_e( 'Cookie token:', 'epappous-club' ); ?></strong> <code style="font-size:11px;"><?php echo esc_html( $click['cookie_token'] ); ?></code></li>
                                        <li><strong><?php esc_html_e( 'Πρώτο click:', 'epappous-club' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i:s', $first_ts ) ); ?></li>
                                        <li><strong><?php esc_html_e( 'Τελευταίο click:', 'epappous-club' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i:s', $last_ts ) ); ?></li>
                                        <li><strong><?php esc_html_e( 'Σύνολο clicks:', 'epappous-club' ); ?></strong> <?php echo (int) $click['click_count']; ?></li>
                                        <li><strong><?php esc_html_e( 'Cookie λήγει:', 'epappous-club' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i', $expires_ts ) ); ?></li>
                                    </ul>
                                    <div style="margin-top:.6em;">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                            <?php wp_nonce_field( 'epc_referral_reconcile_click', 'epc_referral_reconcile_click_nonce' ); ?>
                                            <input type="hidden" name="action" value="epc_referral_reconcile_click" />
                                            <input type="hidden" name="click_id" value="<?php echo (int) $click['id']; ?>" />
                                            <button type="submit" class="button button-secondary button-small">
                                                <?php esc_html_e( 'Πλήρης έλεγχος 90 ημερών', 'epappous-club' ); ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div>
                                    <h4 style="margin:0 0 .3em;"><?php esc_html_e( 'Referrer', 'epappous-club' ); ?></h4>
                                    <?php if ( $referrer_row ) : ?>
                                        <ul style="margin:0; line-height:1.7;">
                                            <li><strong><?php esc_html_e( 'Όνομα:', 'epappous-club' ); ?></strong> <?php echo esc_html( trim( $referrer_row['first_name'] . ' ' . $referrer_row['last_name'] ) ); ?></li>
                                            <li><strong>Email:</strong> <?php echo esc_html( $referrer_row['email'] ); ?></li>
                                            <li><strong><?php esc_html_e( 'Member ID:', 'epappous-club' ); ?></strong> <?php echo (int) $referrer_row['id']; ?></li>
                                            <li><strong>WP user ID:</strong> <?php echo (int) ( $referrer_row['user_id'] ?? 0 ); ?></li>
                                            <li><strong><?php esc_html_e( 'Referral code:', 'epappous-club' ); ?></strong> <code><?php echo esc_html( $referrer_row['referral_code'] ); ?></code></li>
                                        </ul>
                                    <?php else : ?>
                                        <p style="margin:0;"><em><?php esc_html_e( 'Ο referrer έχει διαγραφεί.', 'epappous-club' ); ?></em></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h4 style="margin:0 0 .3em;"><?php esc_html_e( 'Εγγραφή φίλου', 'epappous-club' ); ?></h4>
                                    <?php if ( $has_member && $member_row ) : ?>
                                        <ul style="margin:0; line-height:1.7;">
                                            <li><strong><?php esc_html_e( 'Όνομα:', 'epappous-club' ); ?></strong> <?php echo esc_html( trim( $member_row['first_name'] . ' ' . $member_row['last_name'] ) ); ?></li>
                                            <li><strong>Email:</strong> <?php echo esc_html( $member_row['email'] ); ?></li>
                                            <li><strong><?php esc_html_e( 'Username:', 'epappous-club' ); ?></strong>
                                                <?php
                                                if ( $member_user ) {
                                                    echo '<code>' . esc_html( $member_user->user_login ) . '</code>';
                                                } else {
                                                    echo '<em>' . esc_html__( 'δεν συνδέθηκε με WP user', 'epappous-club' ) . '</em>';
                                                }
                                                ?>
                                            </li>
                                            <li><strong><?php esc_html_e( 'Member ID:', 'epappous-club' ); ?></strong> <?php echo (int) $member_row['id']; ?></li>
                                            <li><strong>WP user ID:</strong> <?php echo (int) $member_user_id; ?></li>
                                            <li><strong><?php esc_html_e( 'Έγινε μέλος:', 'epappous-club' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $click['converted_at'] ) ) ); ?></li>
                                        </ul>
                                    <?php else : ?>
                                        <p style="margin:0;"><em><?php esc_html_e( 'Δεν έχει εγγραφεί ακόμα.', 'epappous-club' ); ?></em></p>
                                        <?php if ( ! empty( $click['referred_email'] ) ) : ?>
                                            <p style="margin:.4em 0 0;">
                                                <small><?php esc_html_e( 'Καταγεγραμμένο email:', 'epappous-club' ); ?> <code><?php echo esc_html( $click['referred_email'] ); ?></code></small>
                                            </p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h4 style="margin:0 0 .3em;"><?php esc_html_e( 'Αγορά φίλου', 'epappous-club' ); ?></h4>
                                    <?php if ( $has_purchase && $order ) :
                                        $order_url = admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' );
                                        ?>
                                        <ul style="margin:0; line-height:1.7;">
                                            <li><strong><?php esc_html_e( 'Order ID:', 'epappous-club' ); ?></strong>
                                                <a href="<?php echo esc_url( $order_url ); ?>">#<?php echo (int) $order->get_id(); ?></a>
                                            </li>
                                            <li><strong><?php esc_html_e( 'Σύνολο:', 'epappous-club' ); ?></strong> <?php echo wp_kses_post( wc_price( (float) $click['purchase_total'], [ 'currency' => $order->get_currency() ] ) ); ?></li>
                                            <li><strong><?php esc_html_e( 'Status:', 'epappous-club' ); ?></strong> <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></li>
                                            <li><strong>Email:</strong> <?php echo esc_html( $order->get_billing_email() ); ?></li>
                                            <li><strong><?php esc_html_e( 'Ημερομηνία αγοράς:', 'epappous-club' ); ?></strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $click['purchased_at'] ) ) ); ?></li>
                                        </ul>
                                    <?php elseif ( $has_purchase ) : ?>
                                        <ul style="margin:0; line-height:1.7;">
                                            <li><strong><?php esc_html_e( 'Order ID:', 'epappous-club' ); ?></strong> #<?php echo (int) $click['purchased_order_id']; ?> <em>(<?php esc_html_e( 'δεν βρέθηκε στο WC', 'epappous-club' ); ?>)</em></li>
                                            <li><strong><?php esc_html_e( 'Σύνολο:', 'epappous-club' ); ?></strong> <?php echo wp_kses_post( wc_price( (float) $click['purchase_total'] ) ); ?></li>
                                        </ul>
                                    <?php else : ?>
                                        <p style="margin:0;"><em><?php esc_html_e( 'Δεν έχει κάνει αγορά ακόμα.', 'epappous-club' ); ?></em></p>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.6em; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                            <?php wp_nonce_field( 'epc_referral_attach_purchase_order', 'epc_referral_attach_purchase_order_nonce' ); ?>
                                            <input type="hidden" name="action" value="epc_referral_attach_purchase_order" />
                                            <input type="hidden" name="click_id" value="<?php echo (int) $click['id']; ?>" />
                                            <label for="manual-order-<?php echo (int) $click['id']; ?>"><strong><?php esc_html_e( 'Χειροκίνητο Order ID:', 'epappous-club' ); ?></strong></label>
                                            <input id="manual-order-<?php echo (int) $click['id']; ?>" type="number" min="1" name="manual_order_id" placeholder="π.χ. 1234" style="width:120px;" required />
                                            <button type="submit" class="button button-small">
                                                <?php esc_html_e( 'Επιβεβαίωση αγοράς', 'epappous-club' ); ?>
                                            </button>
                                        </form>
                                        <p class="description" style="margin:.4em 0 0;">
                                            <?php esc_html_e( 'Θα γίνει επιβεβαίωση ότι το Order είναι processing/completed και ότι το billing email ταιριάζει με το email του φίλου.', 'epappous-club' ); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h4 style="margin:0 0 .3em;"><?php esc_html_e( 'Ανταμοιβή', 'epappous-club' ); ?></h4>
                                    <?php
                                    $reward_referrer = (int) EPC_Settings::get( 'epc_referral_reward_referrer' );
                                    $reward_referred = (int) EPC_Settings::get( 'epc_referral_reward_referred' );
                                    ?>
                                    <ul style="margin:0; line-height:1.7;">
                                        <li><strong><?php esc_html_e( 'Πόντοι referrer:', 'epappous-club' ); ?></strong> <?php echo (int) $reward_referrer; ?></li>
                                        <li><strong><?php esc_html_e( 'Πόντοι φίλου:', 'epappous-club' ); ?></strong> <?php echo (int) $reward_referred; ?></li>
                                        <li><strong><?php esc_html_e( 'Δόθηκε:', 'epappous-club' ); ?></strong>
                                            <?php
                                            if ( $is_rewarded ) {
                                                echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $click['rewarded_at'] ) ) );
                                            } else {
                                                $min_order = (float) EPC_Settings::get( 'epc_referral_min_order' );
                                                if ( $has_member && $has_purchase && $min_order > 0 && (float) $click['purchase_total'] < $min_order ) {
                                                    printf(
                                                        /* translators: %s: minimum order amount */
                                                        esc_html__( 'Όχι — έγινε αγορά, αλλά δεν καλύφθηκε το ελάχιστο ποσό (%s).', 'epappous-club' ),
                                                        wp_kses_post( wc_price( $min_order ) )
                                                    );
                                                    echo '<br /><small>' . esc_html__( 'Η αγορά έχει καταγραφεί κανονικά, αλλά δεν αποδίδονται πόντοι κάτω από το minimum.', 'epappous-club' ) . '</small>';
                                                } else {
                                                $missing = [];
                                                if ( ! $has_member ) {
                                                    $missing[] = __( 'εγγραφή μέλους', 'epappous-club' );
                                                }
                                                if ( ! $has_purchase ) {
                                                    $missing[] = __( 'αγορά', 'epappous-club' );
                                                }
                                                if ( $missing ) {
                                                    printf(
                                                        /* translators: %s: missing conditions */
                                                        esc_html__( 'Όχι — λείπει: %s', 'epappous-club' ),
                                                        esc_html( implode( ' & ', $missing ) )
                                                    );
                                                } else {
                                                    esc_html_e( 'Όχι ακόμα', 'epappous-club' );
                                                }
                                                }
                                            }
                                            ?>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>
            (function($){
                $(document).on('click', '.epc-debug-toggle', function(){
                    var target = '#' + $(this).data('target');
                    $(target).toggle();
                });
            })(jQuery);
        </script>

        <?php if ( $click_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'    => add_query_arg( 'cpaged', '%#%' ),
                        'format'  => '',
                        'current' => $click_page,
                        'total'   => $click_pages,
                    ] );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    $reconcile_result = get_transient( 'epc_referral_reconcile_last' );
    $has_reconcile    = is_array( $reconcile_result ) && ! empty( $reconcile_result['ran_at'] );
    ?>
    <details class="epc-referrals-retro-accordion" style="margin-top:20px;">
        <summary>
            <strong><?php esc_html_e( 'Retrospective έλεγχος παραγγελιών', 'epappous-club' ); ?></strong>
        </summary>

        <div class="epc-info-box epc-referrals-retrospective-box" style="margin-top:10px;">
            <p style="margin-top:0;">
                <?php esc_html_e( 'Ελέγχει παλιές processing/completed παραγγελίες με referral meta και επανεφαρμόζει το reconciliation ώστε να αποδοθούν πόντοι όπου λείπουν.', 'epappous-club' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <?php wp_nonce_field( 'epc_referral_reconcile_orders', 'epc_referral_reconcile_nonce' ); ?>
                <input type="hidden" name="action" value="epc_referral_reconcile_orders" />
                <label for="epc-reconcile-limit"><strong><?php esc_html_e( 'Μέγιστες παραγγελίες', 'epappous-club' ); ?>:</strong></label>
                <input id="epc-reconcile-limit" type="number" name="limit" min="1" max="1000" value="250" style="width:90px;" />
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Εκτέλεση ελέγχου', 'epappous-club' ); ?>
                </button>
            </form>
            <p class="description" style="margin-bottom:0;">
                <?php esc_html_e( 'Το εργαλείο είναι idempotent: δεν διπλοδίνει πόντους σε ήδη ολοκληρωμένα referrals.', 'epappous-club' ); ?>
            </p>
        </div>

        <?php if ( $has_reconcile ) : ?>
            <?php $ran_at = date_i18n( 'd/m/Y H:i:s', (int) $reconcile_result['ran_at'] ); ?>
            <div class="notice notice-info epc-retrospective-result" style="margin:10px 0 0; padding:8px 12px;">
                <p style="margin:0; font-size:12px; line-height:1.4;">
                    <strong><?php esc_html_e( 'Τελευταίος αναδρομικός έλεγχος:', 'epappous-club' ); ?></strong>
                    <?php echo esc_html( $ran_at ); ?>
                    &nbsp;•&nbsp;
                    <?php
                    printf(
                        /* translators: 1: checked orders count, 2: repaired orders count */
                        esc_html__( '%1$d έλεγχοι, %2$d διορθώσεις', 'epappous-club' ),
                        (int) ( $reconcile_result['checked'] ?? 0 ),
                        (int) ( $reconcile_result['repaired'] ?? 0 )
                    );
                    ?>
                </p>
                <?php if ( ! empty( $reconcile_result['summary'] ) && is_array( $reconcile_result['summary'] ) ) : ?>
                    <details style="margin-top:4px;">
                        <summary style="font-size:12px;"><?php esc_html_e( 'Αναλυτικό log', 'epappous-club' ); ?></summary>
                        <ul style="margin:.4em 0 0 1.1em; list-style:disc;">
                            <?php foreach ( $reconcile_result['summary'] as $line ) : ?>
                                <li><code style="font-size:11px;"><?php echo esc_html( (string) $line ); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </details>
</div>
