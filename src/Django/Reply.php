<?php
/**
 * One reply of Django.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Django;

defined( 'ABSPATH' ) || exit;

/**
 * Status and decoded body of a call, with secret keys removed and the customer error
 * recognised by its shape.
 */
final class Reply {

	public const SECRET_KEYS = array( 'webhook_secret', 'consumer_secret', 'odoo_password', 'middleware_api_key' );

	/**
	 * Characters of a raw body kept for the log.
	 */
	private const SNIPPET = 300;

	private $status;
	private $ok;
	private $data;
	private $code      = '';
	private $message   = '';
	private $field     = '';
	private $facing    = false;
	private $transport = false;
	private $raw       = '';

	/**
	 * Use the factories.
	 */
	private function __construct() {}

	/**
	 * A reply from wp_remote_request().
	 *
	 * @param array<string, mixed>|\WP_Error $response Response.
	 */
	public static function from_response( $response ): Reply {
		$r = new self();

		if ( is_wp_error( $response ) ) {
			$r->status    = 0;
			$r->ok        = false;
			$r->data      = array();
			$r->transport = true;
			$r->code      = 'unreachable';
			$r->raw       = $response->get_error_code() . ': ' . $response->get_error_message();

			return $r;
		}

		$r->status = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		$r->raw    = substr( $body, 0, self::SNIPPET );
		$success   = $r->status >= 200 && $r->status < 300;
		$decoded   = '' === trim( $body ) ? array() : json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			// Invalid JSON, or a bare scalar: nothing usable, even with a 2xx status.
			$r->ok   = false;
			$r->data = array();

			return $r;
		}

		$r->ok   = $success;
		$r->data = self::strip( $decoded );

		if ( $r->status >= 400 && isset( $decoded['code'], $decoded['message'] ) && is_string( $decoded['code'] ) && is_string( $decoded['message'] ) && '' !== $decoded['code'] && '' !== $decoded['message'] ) {
			$r->facing  = true;
			$r->code    = $decoded['code'];
			$r->message = $decoded['message'];
			$r->field   = isset( $decoded['field'] ) && is_string( $decoded['field'] ) ? $decoded['field'] : '';
		}

		return $r;
	}

	/**
	 * A reply for a call that was never made.
	 *
	 * @param string $why Reason, for the log.
	 */
	public static function unavailable( string $why ): Reply {
		$r         = new self();
		$r->status = 0;
		$r->ok     = false;
		$r->data   = array();
		$r->code   = 'unavailable';
		$r->raw    = $why;

		return $r;
	}

	/**
	 * Removes the secret keys at any depth.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	private static function strip( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, self::SECRET_KEYS, true ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::strip( $value );
			}
		}

		return $data;
	}

	public function ok(): bool {
		return $this->ok;
	}

	public function status(): int {
		return $this->status;
	}

	/**
	 * Decoded body, without secret keys.
	 *
	 * @return array<mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	public function code(): string {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	public function field(): string {
		return $this->field;
	}

	/**
	 * Did Django send a `{code, message}` error, so that its message may be shown?
	 */
	public function customer_facing(): bool {
		return $this->facing;
	}

	/**
	 * Was there no answer at all (timeout, refused connection)?
	 */
	public function transport_error(): bool {
		return $this->transport;
	}

	/**
	 * The start of the raw body (or the transport error), for the log only.
	 */
	public function raw(): string {
		return $this->raw;
	}
}
