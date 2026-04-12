<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Birthday Bonus System
 *
 * Uses WP-Cron to run a daily check. For every active member whose
 * date_of_birth matches today's month and day, award the configured
 * birthday bonus points — but only once per calendar year.
 */
class EPC_Birthday {

    const CRON_HOOK = 'epc_daily_birthday_check';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'process_birthdays' ] );
    }

    /**
     * Schedule the daily event (called on plugin activation).
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( strtotime( 'today 07:00:00' ), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Remove the scheduled event (called on plugin deactivation).
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Find all active members with a birthday today and award points.
     */
    public function process_birthdays() {
        $bonus = (int) EPC_Settings::get( 'epc_birthday_bonus' );
        if ( $bonus < 1 ) {
            return;
        }

        if ( EPC_Settings::get( 'epc_club_enabled' ) !== '1' ) {
            return;
        }

        global $wpdb;

        $today_month = gmdate( 'm' );
        $today_day   = gmdate( 'd' );
        $this_year   = (int) gmdate( 'Y' );

        $members = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, first_name, email
                 FROM {$wpdb->prefix}epc_members
                 WHERE status = 'active'
                   AND date_of_birth IS NOT NULL
                   AND MONTH(date_of_birth) = %d
                   AND DAY(date_of_birth) = %d",
                $today_month,
                $today_day
            )
        );

        if ( empty( $members ) ) {
            return;
        }

        foreach ( $members as $member ) {
            if ( $this->already_awarded_this_year( (int) $member->id, $this_year ) ) {
                continue;
            }

            $this->award_points( (int) $member->id, $bonus, $this_year );

            do_action( 'epc_birthday_bonus_awarded', (int) $member->id, $bonus );
        }
    }

    /**
     * Check if the member already received a birthday bonus this year.
     */
    private function already_awarded_this_year( int $member_id, int $year ): bool {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->prefix}epc_points_log
                 WHERE member_id = %d
                   AND reason = 'birthday_bonus'
                   AND YEAR(created_at) = %d",
                $member_id,
                $year
            )
        );

        return $count > 0;
    }

    /**
     * Award birthday bonus points and log the transaction.
     */
    private function award_points( int $member_id, int $points, int $year ) {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}epc_members SET points = points + %d WHERE id = %d",
                $points,
                $member_id
            )
        );

        $wpdb->insert(
            "{$wpdb->prefix}epc_points_log",
            [
                'member_id'      => $member_id,
                'points'         => $points,
                'reason'         => 'birthday_bonus',
                'reference_type' => 'birthday',
                'reference_id'   => $year,
            ],
            [ '%d', '%d', '%s', '%s', '%d' ]
        );
    }
}
