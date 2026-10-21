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

	public const GENERIC     = 'Something went wrong on our side. Please try again in a moment.';
	public const UNREACHABLE = 'OdooWoo could not be reached. Please try again in a moment.';
	public const NOT_FOUND   = 'This dashboard was not found.';

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
				'message' => __( self::UNREACHABLE, 'odoowoo-portal' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			);
		}

		return array(
			'code'    => 'server_error',
			'message' => __( self::GENERIC, 'odoowoo-portal' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
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

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the site owner's log.
	}
}
