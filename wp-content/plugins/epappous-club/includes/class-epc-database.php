<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EPC_Database {

    const DB_VERSION_OPTION = 'epc_db_version';
    const DB_VERSION        = '1.4.0';

    public static function activate() {
        self::create_tables();
        self::seed_defaults();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = [];

        // Members table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(200) NOT NULL,
            phone VARCHAR(30) DEFAULT '',
            date_of_birth DATE DEFAULT NULL,
            referral_code VARCHAR(20) NOT NULL,
            referred_by BIGINT UNSIGNED DEFAULT NULL,
            points INT UNSIGNED DEFAULT 0,
            tier VARCHAR(30) DEFAULT 'basic',
            status VARCHAR(20) DEFAULT 'active',
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY referral_code (referral_code),
            UNIQUE KEY email (email),
            KEY user_id (user_id),
            KEY referred_by (referred_by),
            KEY status (status)
        ) {$charset};";

        // Referrals tracking
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_referrals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_member_id BIGINT UNSIGNED NOT NULL,
            referred_member_id BIGINT UNSIGNED DEFAULT NULL,
            referred_email VARCHAR(200) NOT NULL,
            type ENUM('membership','purchase') NOT NULL DEFAULT 'membership',
            order_id BIGINT UNSIGNED DEFAULT NULL,
            reward_points INT UNSIGNED DEFAULT 0,
            reward_type VARCHAR(30) DEFAULT 'points',
            reward_given TINYINT(1) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY referrer_member_id (referrer_member_id),
            KEY referred_member_id (referred_member_id),
            KEY status (status),
            KEY type (type)
        ) {$charset};";

        // (Removed in 1.15.0) The legacy stand-alone gift catalog tables
        // wp_epc_gift_products and wp_epc_gift_redemptions are no longer
        // created. Existing rows are left untouched on upgrades; drop them
        // manually if you want a fully clean DB. Gift products now live as
        // regular WooCommerce products in the configured gift category, with
        // their points cost stored in WooCommerce product meta.

        // Admin notes on users (not tied to club membership)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_member_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY author_id (author_id)
        ) {$charset};";

        // (Removed in 1.15.0) wp_epc_gift_rules — replaced by the WooCommerce
        // gift category + per-product points meta. Existing rows are left in
        // place untouched; drop manually if undesired.

        // Referral clicks (one row per referral cookie token / visitor that
        // landed on the site via ?ref=CODE). Used to surface pending leads in
        // the admin Referrals page and to mark them "converted" once the
        // visitor actually registers as a member.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_referral_clicks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_member_id BIGINT UNSIGNED NOT NULL,
            ref_code VARCHAR(40) NOT NULL,
            cookie_token VARCHAR(64) NOT NULL,
            click_count INT UNSIGNED DEFAULT 1,
            first_clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            converted_member_id BIGINT UNSIGNED DEFAULT NULL,
            converted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cookie_token (cookie_token),
            KEY referrer_member_id (referrer_member_id),
            KEY converted_member_id (converted_member_id),
            KEY first_clicked_at (first_clicked_at)
        ) {$charset};";

        // Points log
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_points_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            points INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            reference_type VARCHAR(50) DEFAULT '',
            reference_id BIGINT UNSIGNED DEFAULT NULL,
            admin_user_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY member_id (member_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    public static function maybe_upgrade() {
        $current = get_option( self::DB_VERSION_OPTION, '1.0.0' );
        if ( version_compare( $current, '1.1.0', '<' ) ) {
            global $wpdb;
            $col = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}epc_points_log LIKE 'admin_user_id'" );
            if ( empty( $col ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}epc_points_log ADD COLUMN admin_user_id BIGINT UNSIGNED DEFAULT NULL AFTER reference_id" );
            }
            update_option( self::DB_VERSION_OPTION, '1.1.0' );
            $current = '1.1.0';
        }
        if ( version_compare( $current, '1.2.0', '<' ) ) {
            global $wpdb;
            $col = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}epc_member_notes LIKE 'updated_at'" );
            if ( empty( $col ) ) {
                $wpdb->query( "ALTER TABLE {$wpdb->prefix}epc_member_notes ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at" );
            }
            update_option( self::DB_VERSION_OPTION, '1.2.0' );
            $current = '1.2.0';
        }
        if ( version_compare( $current, '1.3.0', '<' ) ) {
            EPC_Member_Sync::backfill_b2bking_club_group_for_all_members();
            update_option( self::DB_VERSION_OPTION, '1.3.0' );
            $current = '1.3.0';
        }
        if ( version_compare( $current, '1.3.1', '<' ) ) {
            EPC_Member_Sync::backfill_b2bking_club_group_for_all_members();
            update_option( self::DB_VERSION_OPTION, '1.3.1' );
            $current = '1.3.1';
        }
        if ( version_compare( $current, '1.3.2', '<' ) ) {
            EPC_Member_Sync::backfill_b2bking_club_group_for_all_members();
            EPC_Member_Sync::backfill_members_from_b2bking_group();
            update_option( self::DB_VERSION_OPTION, '1.3.2' );
            $current = '1.3.2';
        }
        if ( version_compare( $current, '1.4.0', '<' ) ) {
            // Make sure the referral clicks table exists for existing installs.
            self::create_tables();
            update_option( self::DB_VERSION_OPTION, '1.4.0' );
        }
    }

    public static function seed_defaults() {
        $defaults = EPC_Settings::defaults();
        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                update_option( $key, $value );
            }
        }
    }
}
