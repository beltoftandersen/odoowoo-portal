<?php
/**
 * What the browser may be told about a Django reply.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Django;

defined( 'ABSPATH' ) || exit;

/**
 * The customer error allow-list: only a `{code, message, field?}` body from Django is shown;
 * everything else becomes a fixed message, and the raw text is logged instead.
 */
final class Messages {

	/**
	 * The fixed messages, translatable.
	 */
	public static function generic(): string {
		return __( 'Something went wrong on our side. Please try again in a moment.', 'odoowoo-portal' );
	}

	public static function unreachable(): string {
		return __( 'OdooWoo could not be reached. Please try again in a moment.', 'odoowoo-portal' );
	}

	public static function not_found(): string {
		return __( 'This dashboard was not found.', 'odoowoo-portal' );
	}

	/**
	 * The error for the browser.
	 *
	 * @param Reply $r Reply.
	 * @return array{code: string, message: string, field?: string}
	 */
	public static function from_reply( Reply $r ): array {
		if ( $r->customer_facing() ) {
			$error = array(
				'code'    => $r->code(),
				'message' => $r->message(),
			);

			if ( '' !== $r->field() ) {
				$error['field'] = $r->field();
			}

			return $error;
		}

		if ( $r->transport_error() ) {
			return array(
				'code'    => 'unreachable',
				'message' => self::unreachable(),
			);
		}

		return array(
			'code'    => 'server_error',
			'message' => self::generic(),
		);
	}

	/**
	 * Logs a line for the site owner. Never shown to a customer.
	 *
	 * @param string $line Line.
	 */
	public static function log( string $line ): void {
		$line = 'OdooWoo portal: ' . $line;

		/**
		 * Fires when the portal logs a line (a reply it will not show).
		 *
		 * @param string $line Line.
		 */
		do_action( 'odoowoo_portal_log', $line );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $line, array( 'source' => 'odoowoo-portal' ) );
		}
	}
}
