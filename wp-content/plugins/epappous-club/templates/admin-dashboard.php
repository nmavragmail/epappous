<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$total_members  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members" );
$active_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_members WHERE status = 'active'" );
$total_referrals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals" );
$completed_referrals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_referrals WHERE status = 'completed'" );
$total_gifts    = EPC_Gifts::count();
$active_gifts   = EPC_Gifts::count( true );
$total_redemptions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epc_gift_redemptions" );
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

        <div class="epc-stat-card epc-stat-gifts">
            <div class="epc-stat-icon"><span class="dashicons dashicons-cart"></span></div>
            <div class="epc-stat-body">
                <span class="epc-stat-number"><?php echo esc_html( $active_gifts ); ?></span>
                <span class="epc-stat-label"><?php esc_html_e( 'Ενεργά Δώρα', 'epappous-club' ); ?></span>
                <span class="epc-stat-sub"><?php printf( esc_html__( '%d συνολικά', 'epappous-club' ), $total_gifts ); ?></span>
            </div>
        </div>

        <div class="epc-stat-card epc-stat-redemptions">
            <div class="epc-stat-icon"><span class="dashicons dashicons-star-filled"></span></div>
            <div class="epc-stat-body">
                <span class="epc-stat-number"><?php echo esc_html( $total_redemptions ); ?></span>
                <span class="epc-stat-label"><?php esc_html_e( 'Εξαργυρώσεις', 'epappous-club' ); ?></span>
            </div>
        </div>
    </div>

    <div class="epc-quick-links">
        <h2><?php esc_html_e( 'Γρήγοροι Σύνδεσμοι', 'epappous-club' ); ?></h2>
        <div class="epc-links-grid">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-settings' ) ); ?>" class="epc-link-card">
                <span class="dashicons dashicons-admin-settings"></span>
                <span><?php esc_html_e( 'Ρυθμίσεις', 'epappous-club' ); ?></span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=epc-gifts' ) ); ?>" class="epc-link-card">
                <span class="dashicons dashicons-cart"></span>
                <span><?php esc_html_e( 'Διαχείριση Δώρων', 'epappous-club' ); ?></span>
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
