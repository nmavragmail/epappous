<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EPC_Database {

    const DB_VERSION_OPTION = 'epc_db_version';
    const DB_VERSION        = '1.0.0';

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

        // Gift products
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_gift_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT '',
            points_required INT UNSIGNED DEFAULT 0,
            stock INT DEFAULT -1,
            image_url VARCHAR(500) DEFAULT '',
            is_active TINYINT(1) DEFAULT 1,
            tier_required VARCHAR(30) DEFAULT 'basic',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY is_active (is_active),
            KEY tier_required (tier_required)
        ) {$charset};";

        // Gift redemptions
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_gift_redemptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            gift_product_id BIGINT UNSIGNED NOT NULL,
            points_spent INT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fulfilled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY gift_product_id (gift_product_id),
            KEY status (status)
        ) {$charset};";

        // Admin notes on users (not tied to club membership)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_member_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY author_id (author_id)
        ) {$charset};";

        // Gift rules (WC product/category/tag based)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_gift_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_type ENUM('product','category','tag') NOT NULL,
            rule_value BIGINT UNSIGNED NOT NULL,
            points_required INT UNSIGNED DEFAULT 0,
            tier_required VARCHAR(30) DEFAULT 'basic',
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY rule_type (rule_type),
            KEY is_active (is_active)
        ) {$charset};";

        // Points log
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}epc_points_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            points INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            reference_type VARCHAR(50) DEFAULT '',
            reference_id BIGINT UNSIGNED DEFAULT NULL,
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

    public static function seed_defaults() {
        $defaults = EPC_Settings::defaults();
        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                update_option( $key, $value );
            }
        }
    }
}
