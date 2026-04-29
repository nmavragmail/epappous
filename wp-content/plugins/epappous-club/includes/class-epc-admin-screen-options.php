<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screen Options: items per page on Pappou Club list screens.
 *
 * Defaults to 50; change via Screen Options ("Number of items per page").
 */
class EPC_Admin_Screen_Options {

	public const OPTION_MEMBERS    = 'epc_members_per_page';
	public const OPTION_REF_MAIN   = 'epc_ref_table_per_page';
	public const OPTION_REF_CLICKS = 'epc_ref_clicks_per_page';
	public const OPTION_POINTS     = 'epc_points_per_page';

	public const PER_PAGE_CAP = 999;

	private const DEFAULT_PAGE = 50;

	public static function boot(): void {
		add_action(
			'init',
			function () {
				self::register_save_filters( self::OPTION_MEMBERS );
				self::register_save_filters( self::OPTION_REF_MAIN );
				self::register_save_filters( self::OPTION_REF_CLICKS );
				self::register_save_filters( self::OPTION_POINTS );
			}
		);

		add_action( 'current_screen', [ __CLASS__, 'on_current_screen' ], 5 );
	}

	/**
	 * @param string $option Meta key for add_screen_option( 'per_page' ).
	 */
	private static function register_save_filters( string $option ): void {
		add_filter(
			'set-screen-option-' . $option,
			function ( $saved, string $opt, $value ): int {
				unset( $saved, $opt );
				return max( 1, min( self::PER_PAGE_CAP, absint( $value ) ) );
			},
			10,
			3
		);
		add_filter(
			'set_screen_option_' . $option,
			function ( $saved, string $opt, $value ): int {
				unset( $saved, $opt );
				return max( 1, min( self::PER_PAGE_CAP, absint( $value ) ) );
			},
			10,
			3
		);
	}

	public static function get_saved( string $option_name, int $fallback = self::DEFAULT_PAGE ): int {
		$uid = get_current_user_id();
		if ( $uid < 1 ) {
			return $fallback;
		}
		$raw = get_user_meta( $uid, $option_name, true );
		$n   = '' === (string) $raw ? $fallback : (int) $raw;
		if ( $n < 1 ) {
			return $fallback;
		}
		return min( self::PER_PAGE_CAP, $n );
	}

	/**
	 * @param \WP_Screen $screen Current admin screen.
	 */
	public static function on_current_screen( $screen ): void {
		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hook = isset( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		switch ( $screen->id ) {
			case 'toplevel_page_epc-dashboard':
				if ( '' === $hook || 'epc-dashboard' !== $hook ) {
					break;
				}
				$screen->add_option(
					self::OPTION_MEMBERS,
					[ 'default' => self::DEFAULT_PAGE ]
				);
				add_screen_option(
					'per_page',
					[
						'label'   => __( 'Μέλη ανά σελίδα', 'epappous-club' ),
						'default' => self::DEFAULT_PAGE,
						'option'  => self::OPTION_MEMBERS,
					]
				);
				break;

			case 'epc-dashboard_page_epc-referrals':
				if ( 'epc-referrals' !== $hook ) {
					break;
				}
				$screen->add_option(
					self::OPTION_REF_MAIN,
					[ 'default' => self::DEFAULT_PAGE ]
				);
				add_screen_option(
					'per_page',
					[
						'label'   => __( 'Πίνακας referrals ανά σελίδα', 'epappous-club' ),
						'default' => self::DEFAULT_PAGE,
						'option'  => self::OPTION_REF_MAIN,
					]
				);
				$screen->add_option(
					self::OPTION_REF_CLICKS,
					[ 'default' => self::DEFAULT_PAGE ]
				);
				add_screen_option(
					'per_page',
					[
						'label'   => __( 'Clicks referral ανά σελίδα', 'epappous-club' ),
						'default' => self::DEFAULT_PAGE,
						'option'  => self::OPTION_REF_CLICKS,
					]
				);
				break;

			case 'epc-dashboard_page_epc-points-log':
				if ( 'epc-points-log' !== $hook ) {
					break;
				}
				$screen->add_option(
					self::OPTION_POINTS,
					[ 'default' => self::DEFAULT_PAGE ]
				);
				add_screen_option(
					'per_page',
					[
						'label'   => __( 'Εγγραφές ιστορικού ανά σελίδα', 'epappous-club' ),
						'default' => self::DEFAULT_PAGE,
						'option'  => self::OPTION_POINTS,
					]
				);
				break;
		}
	}
}
