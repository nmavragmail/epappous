<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single entry point for date-of-birth validation (registration, profile, checkout).
 */
class EPC_DOB_Validator {

    /**
     * Whether the string is a valid calendar date YYYY-MM-DD.
     */
    public static function is_valid_ymd( string $dob ): bool {
        $dob = trim( $dob );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return false;
        }
        $parts = array_map( 'intval', explode( '-', $dob ) );
        if ( function_exists( 'wp_checkdate' ) ) {
            return (bool) wp_checkdate( $parts[1], $parts[2], $parts[0], $dob );
        }
        return checkdate( $parts[1], $parts[2], $parts[0] );
    }

    /**
     * Age in full years at "now" (site timezone).
     */
    public static function age_years( string $dob ): ?int {
        if ( ! self::is_valid_ymd( $dob ) ) {
            return null;
        }
        try {
            $birth = new \DateTime( $dob, wp_timezone() );
            $now   = new \DateTime( 'now', wp_timezone() );
            return (int) $now->diff( $birth )->y;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Validate a DOB for club flows.
     *
     * @param string $dob      Raw string (expected Y-m-d from HTML5 date inputs).
     * @param array  $args {
     *     @type bool $required Whether empty is an error.
     *     @type int  $min_age  Minimum age (0 = do not check).
     * }
     * @return true|\WP_Error
     */
    public static function validate_club_dob( string $dob, array $args = [] ) {
        $required = ! empty( $args['required'] );
        $min_age  = isset( $args['min_age'] ) ? max( 0, (int) $args['min_age'] ) : 0;

        $dob = trim( $dob );
        if ( '' === $dob ) {
            if ( $required ) {
                return new \WP_Error(
                    'epc_dob_required',
                    __( 'Χρειάζεται ημερομηνία γέννησης.', 'epappous-club' )
                );
            }
            return true;
        }

        if ( ! self::is_valid_ymd( $dob ) ) {
            return new \WP_Error(
                'epc_dob_invalid',
                __( 'Μη έγκυρη ημερομηνία γέννησης. Χρησιμοποίησε τη μορφή ΕΕΕΕ-MM-ΗΗ.', 'epappous-club' )
            );
        }

        if ( $min_age > 0 ) {
            $age = self::age_years( $dob );
            if ( null === $age ) {
                return new \WP_Error(
                    'epc_dob_invalid',
                    __( 'Μη έγκυρη ημερομηνία γέννησης.', 'epappous-club' )
                );
            }
            if ( $age < $min_age ) {
                return new \WP_Error(
                    'epc_dob_age',
                    sprintf(
                        /* translators: %d: minimum age */
                        __( 'Πρέπει να είσαι τουλάχιστον %d ετών.', 'epappous-club' ),
                        $min_age
                    )
                );
            }
        }

        return true;
    }
}
