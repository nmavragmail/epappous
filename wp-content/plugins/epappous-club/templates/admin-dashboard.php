<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$total_members  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members" );
$active_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE status = 'active'" );
$total_referrals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals" );
$completed_referrals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE status = 'completed'" );

$gift_redemptions_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_points_log WHERE reason = 'gift_redemption'" );
$gift_redemptions_pts   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(ABS(points)),0) FROM {$wpdb->prefix}epc_points_log WHERE reason = 'gift_redemption'" );
?>
<div class="wrap epc-wrap">
    <div class="epc-header">
        <h1>
            <span class="dashicons dashicons-groups"></span>
            <?php echo esc_html( EPC_Settings::get( 'epc_club_name' ) ); ?> — Dashboard
        </h1>
    </div>

    <div class="epc-dashboard-grid">
        <div class="epc-stat-card epc-stat-members">
            <div class="epc-stat-icon"><span class="dashicons dashicons-admin-users"></span></div>
            <div class="epc-stat-body">
                <span class="epc-stat-number"><?php echo esc_html( $active_members ); ?></span>
                <span class="epc-stat-label"><?php esc_html_e( 'Ενεργά Μέλη', 'epappous-club' ); ?></span>
                <span class="epc-stat-sub"><?php printf( esc_html__( '%d συνολικά', 'epappous-club' ), $total_members ); ?></span>
            </div>
        </div>

        <div class="epc-stat-card epc-stat-referrals">
            <div class="epc-stat-icon"><span class="dashicons dashicons-share"></span></div>
            <div class="epc-stat-body">
                <span class="epc-stat-number"><?php echo esc_html( $completed_referrals ); ?></span>
                <span class="epc-stat-label"><?php esc_html_e( 'Referrals', 'epappous-club' ); ?></span>
                <span class="epc-stat-sub"><?php printf( esc_html__( '%d συνολικά', 'epappous-club' ), $total_referrals ); ?></span>
            </div>
        </div>

        <div class="epc-stat-card epc-stat-redemptions">
            <div class="epc-stat-icon"><span class="dashicons dashicons-star-filled"></span></div>
            <div class="epc-stat-body">
                <span class="epc-stat-number"><?php echo esc_html( (string) $gift_redemptions_total ); ?></span>
                <span class="epc-stat-label"><?php esc_html_e( 'Εξαργυρώσεις Δώρων', 'epappous-club' ); ?></span>
                <span class="epc-stat-sub"><?php printf( esc_html__( '%d πόντοι σύνολο', 'epappous-club' ), $gift_redemptions_pts ); ?></span>
            </div>
        </div>
    </div>

    <div class="epc-quick-links">
        <h2><?php esc_html_e( 'Γρήγοροι Σύνδεσμοι', 'epappous-club' ); ?></h2>
        <div class="epc-links-grid">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-members' ) ); ?>" class="epc-link-card">
                <span class="dashicons dashicons-admin-users"></span>
                <span><?php esc_html_e( 'Μέλη', 'epappous-club' ); ?></span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings' ) ); ?>" class="epc-link-card">
                <span class="dashicons dashicons-admin-settings"></span>
                <span><?php esc_html_e( 'Ρυθμίσεις', 'epappous-club' ); ?></span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-referrals' ) ); ?>" class="epc-link-card">
                <span class="dashicons dashicons-share"></span>
                <span><?php esc_html_e( 'Referrals', 'epappous-club' ); ?></span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-points-log' ) ); ?>" class="epc-link-card">
                <span class="dashicons dashicons-list-view"></span>
                <span><?php esc_html_e( 'Ιστορικό Πόντων', 'epappous-club' ); ?></span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings&tab=referral' ) ); ?>" class="epc-link-card">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e( 'Τί είναι το Referral;', 'epappous-club' ); ?></span>
            </a>
        </div>
    </div>
</div>
