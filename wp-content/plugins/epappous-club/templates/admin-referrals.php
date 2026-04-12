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
?>
<div class="wrap epc-wrap">
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
                <?php esc_html_e( 'Η καταγραφή γίνεται σε δύο περιπτώσεις:', 'epappous-club' ); ?>
                <ul>
                    <li><strong><?php esc_html_e( 'Εγγραφή μέλους:', 'epappous-club' ); ?></strong> <?php esc_html_e( 'Όταν ο φίλος γίνει μέλος του club μέσω του referral link.', 'epappous-club' ); ?></li>
                    <li><strong><?php esc_html_e( 'Αγορά:', 'epappous-club' ); ?></strong> <?php esc_html_e( 'Όταν ο φίλος ολοκληρώσει μια παραγγελία στο κατάστημα.', 'epappous-club' ); ?></li>
                </ul>
            </li>
            <li><?php esc_html_e( 'Και οι δύο (referrer & referred) κερδίζουν ανταμοιβή (πόντοι ή έκπτωση, αναλόγως ρυθμίσεων).', 'epappous-club' ); ?></li>
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
</div>
