<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Points Expiry
 *
 * Daily cron that expires points older than epc_points_expiry_days.
 * Works by scanning the points log for net-positive entries older than the
 * threshold that haven't been expired yet, then deducting those points.
 */
class EPC_Expiry {

    const CRON_HOOK = 'epc_daily_expiry_check';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'process_expiry' ] );
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( strtotime( 'today 03:00:00' ), 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    /**
     * Find and expire old points.
     */
    public function process_expiry() {
        $days = (int) EPC_Settings::get( 'epc_points_expiry_days' );
        if ( $days < 1 ) {
            return;
        }

        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // Find positive point entries older than cutoff that haven't been expired
        $entries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pl.id, pl.member_id, pl.points
                 FROM {$wpdb->prefix}epc_points_log pl
                 WHERE pl.points > 0
                   AND pl.reason != 'points_expiry'
                   AND pl.created_at < %s
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}epc_points_log ex
                       WHERE ex.reason = 'points_expiry'
                         AND ex.reference_type = 'expiry_of'
                         AND ex.reference_id = pl.id
                   )",
                $cutoff
            )
        );

        if ( empty( $entries ) ) {
            return;
        }

        foreach ( $entries as $entry ) {
            $member_id = (int) $entry->member_id;
            $points    = (int) $entry->points;

            // Only expire up to the member's current balance
            $current = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT points FROM {$wpdb->prefix}epc_members WHERE id = %d",
                    $member_id
                )
            );

            $to_expire = min( $points, $current );
            if ( $to_expire < 1 ) {
                // Still mark as processed to avoid re-scanning
                $wpdb->insert(
                    "{$wpdb->prefix}epc_points_log",
                    [
                        'member_id'      => $member_id,
                        'points'         => 0,
                        'reason'         => 'points_expiry',
                        'reference_type' => 'expiry_of',
                        'reference_id'   => (int) $entry->id,
                    ],
                    [ '%d', '%d', '%s', '%s', '%d' ]
                );
                continue;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}epc_members SET points = GREATEST(0, CAST(points AS SIGNED) - %d) WHERE id = %d",
                    $to_expire,
                    $member_id
                )
            );

            $wpdb->insert(
                "{$wpdb->prefix}epc_points_log",
                [
                    'member_id'      => $member_id,
                    'points'         => -$to_expire,
                    'reason'         => 'points_expiry',
                    'reference_type' => 'expiry_of',
                    'reference_id'   => (int) $entry->id,
                ],
                [ '%d', '%d', '%s', '%s', '%d' ]
            );

            do_action( 'epc_points_changed', $member_id );
        }
    }
}
