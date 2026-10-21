<?php
/**
 * Signed calls to the OdooWoo Django API.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Django;

use BeltoftServices\Http\Connections;
use BeltoftServices\Http\HttpDriver;
use BeltoftServices\Http\Signer;
use OdooWooPortal\Context;
use OdooWooPortal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that talks to Django. The connection (URL, secret, timeout, header names) is
 * the beltoft-services one; requests are signed by its Signer and sent the way its HTTP
 * driver sends them.
 */
final class Client {

	/**
	 * Calls Django for a license's tenant.
	 *
	 * @param Context              $ctx    Context of the license.
	 * @param string               $method HTTP method.
	 * @param string               $path   Path relative to /api/, for example "dashboards/9/woo-keys/". Trusted: callers build it from ints and fixed words.
	 * @param array<mixed>|null    $body   JSON body, or null for none.
	 * @param array<string, mixed> $query  Query arguments.
	 */
	public static function request( Context $ctx, string $method, string $path, ?array $body = null, array $query = array() ): Reply {
		$tenant = $ctx->tenant();

		if ( '' === $tenant || ! $ctx->calls_allowed() ) {
			return self::refused( 'no tenant that may be called' );
		}

		$connection = self::connection();

		if ( null === $connection ) {
			return self::refused( 'no usable connection' );
		}

		$base = rtrim( (string) $connection['base_url'], '/' );

		if ( '' === $base || ! Connections::scheme_allowed( $base ) ) {
			return self::refused( 'connection URL is not allowed' );
		}

		$url = $base . '/api/' . ltrim( $path, '/' );

		if ( array() !== $query ) {
			$url .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		if ( ! Connections::scheme_allowed( $url ) ) {
			return self::refused( 'request URL is not allowed' );
		}

		$method = strtoupper( $method );
		$json   = null === $body ? '' : (string) wp_json_encode( array() === $body ? (object) array() : $body );

		$signed_path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$signed_query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$signed_path .= '' === $signed_query ? '' : '?' . $signed_query;

		$headers = array_merge(
			array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			Signer::headers( $connection, $method, $signed_path, $json, $tenant )
		);

		$args = array(
			'method'      => $method,
			'timeout'     => (int) $connection['timeout'],
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
		);

		if ( '' !== $json ) {
			$args['body'] = $json;
		}

		$response = HttpDriver::uses_safe_request( $connection, $url ) ? wp_safe_remote_request( $url, $args ) : wp_remote_request( $url, $args );
		$reply    = Reply::from_response( $response );

		if ( ! $reply->ok() && ! $reply->customer_facing() ) {
			Messages::log( sprintf( '%s /api/%s %s', $method, ltrim( (string) strtok( $path, '?' ), '/' ), $reply->log_summary() ) );
		}

		return $reply;
	}

	/**
	 * An unavailable reply, logged.
	 *
	 * @param string $why Reason.
	 */
	private static function refused( string $why ): Reply {
		Messages::log( 'no request made: ' . $why );

		return Reply::unavailable( $why );
	}

	/**
	 * The selected connection with defaults, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function connection(): ?array {
		$id = (string) Settings::get( 'connection_id' );

		if ( '' === $id ) {
			return null;
		}

		/** This filter is documented in beltoft-services, src/Http/HttpDriver.php. */
		$connection = apply_filters( 'beltoft_services_connection', null, $id );

		if ( ! is_array( $connection ) ) {
			return null;
		}

		$connection = array_merge( Connections::defaults(), $connection );

		return '' === (string) $connection['secret'] ? null : $connection;
	}
}
