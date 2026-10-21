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
	 * How long a support session lasts after an arrival.
	 */
	public const SESSION_TTL = 2 * HOUR_IN_SECONDS;

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
				'license'  => $license_id,
				self::FLAG => 1,
				'_wpnonce' => wp_create_nonce( self::action( $license_id ) ),
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
	 * Handles an arrival through the support link: flag, a valid per-license nonce of the
	 * acting user, manage_woocommerce, and the user is not the owner. Every arrival is
	 * logged and (re)starts the user's support session for that license, which is the only
	 * way Context::for_user() gives a non-owner access.
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

		$license = Context::support_license( $user_id, $license_id );

		if ( ! $license || get_current_user_id() !== $user_id ) {
			return false;
		}

		self::grant( $user_id, $license_id );
		Context::reset();
		self::record_visit( $license, $user_id );

		return true;
	}

	/**
	 * Meta key of a user's support session for a license.
	 *
	 * @param int $license_id License id.
	 */
	private static function meta_key( int $license_id ): string {
		return 'odoowoo_support_until_' . $license_id;
	}

	/**
	 * Starts a support session.
	 *
	 * @param int $user_id    Support user.
	 * @param int $license_id License id.
	 */
	public static function grant( int $user_id, int $license_id ): void {
		update_user_meta( $user_id, self::meta_key( $license_id ), time() + self::SESSION_TTL );
	}

	/**
	 * Does the user have a running support session for the license?
	 *
	 * @param int $user_id    User id.
	 * @param int $license_id License id.
	 */
	public static function has_session( int $user_id, int $license_id ): bool {
		return $user_id > 0 && (int) get_user_meta( $user_id, self::meta_key( $license_id ), true ) > time();
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
