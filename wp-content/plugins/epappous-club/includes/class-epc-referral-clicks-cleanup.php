<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deletes old rows from wp_epc_referral_clicks to cap table growth.
 */
class EPC_Referral_Clicks_Cleanup {

    const CRON_HOOK = 'epc_referral_clicks_cleanup_daily';

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
    }

    /**
     * Remove stale unrewarded leads and optionally old rewarded rows.
     */
    public static function run(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'epc_referral_clicks';

        $unrewarded_days = max( 0, (int) EPC_Settings::get( 'epc_referral_clicks_prune_unrewarded_days' ) );
        $rewarded_days   = max( 0, (int) EPC_Settings::get( 'epc_referral_clicks_prune_rewarded_days' ) );

        if ( $unrewarded_days > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE rewarded_at IS NULL AND first_clicked_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
                    $unrewarded_days
                )
            );
        }

        if ( $rewarded_days > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE rewarded_at IS NOT NULL AND rewarded_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
                    $rewarded_days
                )
            );
        }
    }
}
