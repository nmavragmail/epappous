<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tier Management
 *
 * Listens for points changes and recalculates the member's tier
 * based on their lifetime points total and the configured tier thresholds.
 */
class EPC_Tiers {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'epc_points_changed', [ $this, 'recalculate_tier' ] );
    }

    /**
     * Recalculate a member's tier based on current points.
     */
    public function recalculate_tier( int $member_id ) {
        global $wpdb;

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, points, tier FROM {$wpdb->prefix}epc_members WHERE id = %d",
                $member_id
            ),
            ARRAY_A
        );

        if ( ! $member ) {
            return;
        }

        $tiers = self::get_tiers_sorted();
        if ( empty( $tiers ) ) {
            return;
        }

        $new_tier = $tiers[0]['slug'];
        foreach ( $tiers as $tier ) {
            if ( (int) $member['points'] >= (int) $tier['min_points'] ) {
                $new_tier = $tier['slug'];
            }
        }

        if ( $new_tier !== $member['tier'] ) {
            $old_tier = $member['tier'];

            $wpdb->update(
                "{$wpdb->prefix}epc_members",
                [ 'tier' => $new_tier ],
                [ 'id' => $member_id ],
                [ '%s' ],
                [ '%d' ]
            );

            $old_index = self::tier_index( $old_tier, $tiers );
            $new_index = self::tier_index( $new_tier, $tiers );

            if ( $new_index > $old_index ) {
                do_action( 'epc_tier_upgraded', $member_id, $old_tier, $new_tier );
            }
        }
    }

    /**
     * Get tiers sorted by min_points ascending.
     */
    public static function get_tiers_sorted(): array {
        $tiers_json = EPC_Settings::get( 'epc_tiers' );
        $tiers = json_decode( $tiers_json, true );
        if ( ! is_array( $tiers ) || empty( $tiers ) ) {
            return [
                [ 'slug' => 'basic', 'label' => 'Basic', 'min_points' => 0, 'multiplier' => 1.0 ],
            ];
        }
        usort( $tiers, fn( $a, $b ) => ( $a['min_points'] ?? 0 ) <=> ( $b['min_points'] ?? 0 ) );
        return $tiers;
    }

    private static function tier_index( string $slug, array $tiers ): int {
        foreach ( $tiers as $i => $t ) {
            if ( $t['slug'] === $slug ) {
                return $i;
            }
        }
        return 0;
    }
}
