<?php
/**
 * The "Open OdooWoo" button on My Account → Licenses.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Account;

use OdooWooPortal\Context;
use OdooWooPortal\Settings;
use OdooWooPortal\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a link to the app inside the license block of beltoft-licenses.
 */
final class OpenLink {

	/**
	 * Hooks the button into the license block.
	 */
	public static function register(): void {
		add_action( 'beltoft_licenses_account_license', array( __CLASS__, 'render' ), 10, 2 );
	}

	/**
	 * Prints the button for an OdooWoo license of the current user, when the list page is set or the app lives in My Account.
	 *
	 * @param mixed $license License row.
	 * @param mixed $view    Display values (unused).
	 */
	public static function render( $license, $view = array() ): void {
		unset( $view );

		if ( ! is_array( $license ) || empty( $license['id'] ) || ( (int) Settings::get( 'list_page_id' ) <= 0 && ! Area::enabled( (int) $license['id'] ) ) ) {
			return;
		}

		if ( (string) ( $license['product_slug'] ?? '' ) !== (string) Settings::get( 'software_slug' ) ) {
			return;
		}

		$context = Context::for_user( get_current_user_id(), (int) $license['id'] );

		if ( null === $context || 'owner' !== $context->mode() ) {
			return;
		}

		printf(
			'<p class="odoowoo-portal-open"><a class="button woocommerce-button" href="%1$s">%2$s</a></p>',
			esc_url( Urls::license_list( (int) $license['id'] ) ),
			esc_html__( 'Open OdooWoo', 'odoowoo-portal' )
		);
	}
}
