<?php
/**
 * 2NET Loyalty — Birthday Check CLI Script
 *
 * Standalone script that loads WordPress, finds users with birthdays
 * today, and awards them birthday points. Fully idempotent — safe to
 * run multiple times on the same day.
 *
 * Usage:
 *   php /path/to/wp-content/plugins/2net-loyalty/cli/check-birthday.php
 *
 * Recommended cron entry (daily at 08:00):
 *   0 8 * * * /usr/bin/php /var/www/html/wp-content/plugins/2net-loyalty/cli/check-birthday.php >> /var/log/2net-birthday.log 2>&1
 *
 * Or via WP-CLI:
 *   wp eval-file /path/to/wp-content/plugins/2net-loyalty/cli/check-birthday.php
 */

// Determine wp-load.php location.
$wp_load_candidates = [
    dirname( __FILE__, 5 ) . '/wp-load.php',        // standard relative path
    '/var/www/html/wp-load.php',                     // common server path
    getenv( 'WP_LOAD_PATH' ) ?: '',                  // environment override
];

$wp_load = '';
foreach ( $wp_load_candidates as $candidate ) {
    if ( $candidate && file_exists( $candidate ) ) {
        $wp_load = $candidate;
        break;
    }
}

if ( ! $wp_load ) {
    fwrite( STDERR, "[ERROR] Cannot find wp-load.php. Set WP_LOAD_PATH environment variable.\n" );
    exit( 1 );
}

// Load WordPress.
define( 'DOING_CRON', true );
require_once $wp_load;

// Verify plugin is active.
if ( ! class_exists( 'TwoNet_Bonus_Handler' ) ) {
    fwrite( STDERR, "[ERROR] 2NET Loyalty plugin is not active.\n" );
    exit( 1 );
}

if ( ! TwoNet_Loyalty_Core::is_enabled() ) {
    echo "[INFO] 2NET Loyalty is disabled. Exiting.\n";
    exit( 0 );
}

echo sprintf( "[%s] Birthday check started.\n", gmdate( 'Y-m-d H:i:s' ) );

$results = TwoNet_Bonus_Handler::process_birthdays_today();

$awarded_count = count( $results['awarded'] );
$skipped_count = count( $results['skipped'] );

echo sprintf( "[%s] Completed. Awarded: %d, Skipped (already awarded): %d\n",
    gmdate( 'Y-m-d H:i:s' ),
    $awarded_count,
    $skipped_count
);

if ( $awarded_count > 0 ) {
    echo "  Awarded user IDs: " . implode( ', ', $results['awarded'] ) . "\n";
}

if ( $skipped_count > 0 ) {
    echo "  Skipped user IDs: " . implode( ', ', $results['skipped'] ) . "\n";
}

exit( 0 );
