<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$msg = isset( $_GET['epc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['epc_msg'] ) ) : '';

$messages = [
    'created'  => [ 'notice-success', __( 'Το μέλος δημιουργήθηκε. Αν υπήρχε ήδη λογαριασμός WordPress με το ίδιο email, συνδέθηκε και ετέθη στην ομάδα Pappou Club (B2B King).', 'epappous-club' ) ],
    'exists'   => [ 'notice-error', __( 'Αυτό το email είναι ήδη εγγεγραμμένο στο club.', 'epappous-club' ) ],
    'invalid'  => [ 'notice-error', __( 'Συμπληρώστε όνομα, επώνυμο και έγκυρο email.', 'epappous-club' ) ],
    'disabled' => [ 'notice-warning', __( 'Το club δεν είναι ενεργό στις ρυθμίσεις.', 'epappous-club' ) ],
    'error'    => [ 'notice-error', __( 'Σφάλμα κατά την αποθήκευση.', 'epappous-club' ) ],
];

$members = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}epc_members ORDER BY joined_at DESC LIMIT 100",
    ARRAY_A
) ?: [];
?>
<div class="wrap epc-wrap">
    <div class="epc-header">
        <h1>
            <span class="dashicons dashicons-admin-users"></span>
            <?php esc_html_e( 'Μέλη Pappou Club', 'epappous-club' ); ?>
        </h1>
    </div>

    <?php if ( $msg && isset( $messages[ $msg ] ) ) : ?>
        <div class="notice <?php echo esc_attr( $messages[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ][1] ); ?></p></div>
    <?php endif; ?>

    <div class="epc-dashboard-grid" style="grid-template-columns: 1fr; align-items: start; gap: 24px;">
        <div class="epc-card">
            <div class="epc-card-header">
                <h2><?php esc_html_e( 'Πρόσφατα μέλη', 'epappous-club' ); ?></h2>
            </div>
            <div class="epc-card-body" style="overflow-x:auto;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'epappous-club' ); ?></th>
                            <th><?php esc_html_e( 'Ονοματεπώνυμο', 'epappous-club' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'epappous-club' ); ?></th>
                            <th><?php esc_html_e( 'User ID', 'epappous-club' ); ?></th>
                            <th><?php esc_html_e( 'Πόντοι', 'epappous-club' ); ?></th>
                            <th><?php esc_html_e( 'Κατάσταση', 'epappous-club' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $members ) ) : ?>
                            <tr><td colspan="6"><?php esc_html_e( 'Δεν υπάρχουν εγγραφές.', 'epappous-club' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $members as $m ) : ?>
                                <tr>
                                    <td><?php echo (int) $m['id']; ?></td>
                                    <td><?php echo esc_html( trim( ( $m['first_name'] ?? '' ) . ' ' . ( $m['last_name'] ?? '' ) ) ); ?></td>
                                    <td><?php echo esc_html( $m['email'] ?? '' ); ?></td>
                                    <td><?php echo $m['user_id'] ? (int) $m['user_id'] : '—'; ?></td>
                                    <td><?php echo (int) ( $m['points'] ?? 0 ); ?></td>
                                    <td><?php echo esc_html( $m['status'] ?? '' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
