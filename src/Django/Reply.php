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

	private int $status       = 0;
	private bool $ok          = false;
	/** @var array<mixed> */
	private array $data       = array();
	private string $code      = '';
	private string $message   = '';
	private string $field     = '';
	private bool $facing      = false;
	private bool $transport   = false;
	private int $length       = 0;
	private string $why       = '';

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
			$r->why       = (string) $response->get_error_code();

			return $r;
		}

		$r->status = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		$r->length = strlen( $body );
		$success   = $r->status >= 200 && $r->status < 300;
		$decoded   = '' === trim( $body ) ? array() : json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			// Invalid JSON, or a bare scalar: nothing usable, even with a 2xx status.
			return $r;
		}

		$r->ok = $success;

		if ( $r->status >= 400 && isset( $decoded['code'], $decoded['message'] ) && is_string( $decoded['code'] ) && is_string( $decoded['message'] ) && '' !== $decoded['code'] && '' !== $decoded['message'] ) {
			$r->facing  = true;
			$r->code    = $decoded['code'];
			$r->message = $decoded['message'];
			$r->field   = isset( $decoded['field'] ) && is_string( $decoded['field'] ) ? $decoded['field'] : '';
		}

		// Only a success body or a customer error carries data; any other failure body is not trusted.
		if ( $r->ok || $r->facing ) {
			$r->data = self::strip( $decoded );
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
		$r->code   = 'unavailable';
		$r->why    = $why;

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
	 * A line for the log: status, code, body length or the error code. Never body text.
	 */
	public function log_summary(): string {
		return sprintf( 'status=%d code=%s length=%d%s', $this->status, '' === $this->code ? '-' : $this->code, $this->length, '' === $this->why ? '' : ' reason=' . $this->why );
	}
}
