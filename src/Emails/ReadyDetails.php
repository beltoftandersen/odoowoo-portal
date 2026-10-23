<?php
/**
 * The next step in the OdooWoo "service ready" email.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Emails;

use OdooWooPortal\Settings;
use OdooWooPortal\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the dashboard address and what to do first to beltoft-services' ready email
 * (filter beltoft_services_email_details) for an OdooWoo license. No secret, no Django call.
 */
final class ReadyDetails {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'beltoft_services_email_details', array( __CLASS__, 'details' ), 10, 3 );
	}

	/**
	 * The lines for an OdooWoo ready email.
	 *
	 * @param mixed $details  Lines so far.
	 * @param mixed $email_id Email id.
	 * @param mixed $r        Resource (a BeltoftServices\Resource).
	 * @return mixed
	 */
	public static function details( $details, $email_id, $r ) {
		if ( ! is_array( $details ) || 'beltoft_services_ready' !== $email_id || ! is_object( $r ) || ! method_exists( $r, 'license' ) ) {
			return $details;
		}

		$license = $r->license();

		if ( ! is_array( $license ) || empty( $license['id'] ) || (string) ( $license['product_slug'] ?? '' ) !== (string) Settings::get( 'software_slug' ) ) {
			return $details;
		}

		$details[ __( 'Open your dashboard', 'odoowoo-portal' ) ] = Urls::license_list( (int) $license['id'] );
		$details[ __( 'Next step', 'odoowoo-portal' ) ]           = __( 'Open the dashboard, then connect your WooCommerce store and your Odoo under Connections.', 'odoowoo-portal' );

		return $details;
	}
}
