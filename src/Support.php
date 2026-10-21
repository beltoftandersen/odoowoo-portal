<?php
/**
 * Support visits.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

use BeltoftLicenses\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The "View as support" link and the history line each visit leaves.
 */
final class Support {

	public const FLAG = 'odoowoo_support';

	/**
	 * Hooks the admin link.
	 */
	public static function register(): void {
		add_action( 'beltoft_licenses_admin_license', array( __CLASS__, 'admin_link' ) );
	}

	/**
	 * A nonce link to the app, as support, for one license.
	 *
	 * @param int $license_id License id.
	 */
	public static function url( int $license_id ): string {
		return add_query_arg(
			array(
				'license'    => $license_id,
				self::FLAG   => 1,
				'_wpnonce'   => wp_create_nonce( self::action( $license_id ) ),
			),
			Urls::list()
		);
	}

	/**
	 * Nonce action.
	 *
	 * @param int $license_id License id.
	 */
	private static function action( int $license_id ): string {
		return 'odoowoo_support_' . $license_id;
	}

	/**
	 * Records the visit when a request arrived through the support link: flag, valid
	 * nonce, and the user is support for that license (not its owner). Every arrival is logged.
	 *
	 * @param array<string, mixed> $query   Query args (normally $_GET).
	 * @param int                  $user_id Viewer.
	 * @return bool Whether a visit was recorded.
	 */
	public static function arrived( array $query, int $user_id ): bool {
		if ( empty( $query[ self::FLAG ] ) ) {
			return false;
		}

		$license_id = isset( $query['license'] ) ? absint( $query['license'] ) : 0;
		$nonce      = isset( $query['_wpnonce'] ) && is_string( $query['_wpnonce'] ) ? $query['_wpnonce'] : '';

		if ( $license_id <= 0 || ! wp_verify_nonce( $nonce, self::action( $license_id ) ) ) {
			return false;
		}

		$context = Context::for_user( $user_id, $license_id );

		if ( ! $context || 'support' !== $context->mode() ) {
			return false;
		}

		self::record_visit( $context->license(), $user_id );

		return true;
	}

	/**
	 * Writes the history line.
	 *
	 * @param array<string, mixed> $license License.
	 * @param int                  $user_id Support user.
	 */
	public static function record_visit( array $license, int $user_id ): void {
		$user = get_userdata( $user_id );

		Events::add(
			(int) $license['id'],
			'support_view',
			sprintf(
				/* translators: %s: support user's login */
				__( 'Support viewed the OdooWoo app (%s)', 'odoowoo-portal' ),
				$user ? $user->user_login : '#' . $user_id
			)
		);
	}

	/**
	 * "View as support" on the license screen.
	 *
	 * @param array<string, mixed> $license License.
	 */
	public static function admin_link( $license ): void {
		if ( ! is_array( $license ) || (string) Settings::get( 'software_slug' ) !== ( $license['product_slug'] ?? '' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		printf(
			'<p><a class="button" href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( self::url( (int) $license['id'] ) ),
			esc_html__( 'View as support', 'odoowoo-portal' )
		);
	}
}
