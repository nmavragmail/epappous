<?php
/**
 * Loads WordPress when available; otherwise minimal stubs for pure unit checks.
 */

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';

if ( file_exists( $wp_load ) ) {
    define( 'WP_USE_THEMES', false );
    require_once $wp_load;
} else {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/' );
    }
    if ( ! function_exists( 'wp_checkdate' ) ) {
        /**
         * @param int    $month    Month.
         * @param int    $day      Day.
         * @param int    $year     Year.
         * @param string $source   Original string (unused).
         */
        function wp_checkdate( $month, $day, $year, $source ) {
            return checkdate( (int) $month, (int) $day, (int) $year );
        }
    }
    if ( ! function_exists( 'wp_timezone' ) ) {
        function wp_timezone() {
            return new DateTimeZone( 'UTC' );
        }
    }
    if ( ! class_exists( 'WP_Error', false ) ) {
        /**
         * Minimal stub for tests without full WordPress.
         */
        class WP_Error {
            /** @var string */
            private $code;
            /** @var string */
            private $message;

            /**
             * @param string $code    Error code.
             * @param string $message Message.
             */
            public function __construct( $code = '', $message = '' ) {
                $this->code    = (string) $code;
                $this->message = (string) $message;
            }

            /**
             * @return string
             */
            public function get_error_code() {
                return $this->code;
            }

            /**
             * @return string
             */
            public function get_error_message() {
                return $this->message;
            }
        }
    }
    if ( ! function_exists( 'is_wp_error' ) ) {
        /**
         * @param mixed $thing Value.
         * @return bool
         */
        function is_wp_error( $thing ) {
            return $thing instanceof WP_Error;
        }
    }
}

require_once dirname( __DIR__ ) . '/includes/class-epc-dob-validator.php';
require_once dirname( __DIR__ ) . '/includes/class-epc-referral-clicks-cleanup.php';
