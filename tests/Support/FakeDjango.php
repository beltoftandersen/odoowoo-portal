<?php
/**
 * A fake Django (OdooWoo) for tests: records signed requests and answers from a responder.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests\Support;

/**
 * Installed through pre_http_request; only requests to the connection's base URL are taken.
 */
class FakeDjango {

	/**
	 * Recorded calls: method, url, path_and_query, headers, body, timeout, args.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $calls = array();

	/**
	 * Answers a call with [status, array|string].
	 *
	 * @var callable
	 */
	private $responder;

	/**
	 * Base URL taken by this fake.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * @param callable $responder Gets the call array; returns [status, array|string].
	 * @param string   $base      Base URL.
	 */
	public function __construct( callable $responder, string $base = 'http://127.0.0.1:8101' ) {
		$this->responder = $responder;
		$this->base      = rtrim( $base, '/' );
	}

	/**
	 * The pre_http_request filter.
	 *
	 * @param mixed                $preempt Value so far.
	 * @param array<string, mixed> $args    Request arguments.
	 * @param string               $url     URL.
	 * @return mixed
	 */
	public function handle( $preempt, $args, $url ) {
		if ( ! $this->is_ours( $url ) ) {
			return $preempt;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$qs   = wp_parse_url( $url, PHP_URL_QUERY );

		$headers = array();
		foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
			$headers[ (string) $name ] = (string) $value;
		}

		$body = $args['body'] ?? '';

		if ( is_array( $body ) || is_object( $body ) ) {
			$body = http_build_query( (array) $body );
		}

		$call          = array(
			'method'         => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'url'            => $url,
			'path_and_query' => $path . ( is_string( $qs ) && '' !== $qs ? '?' . $qs : '' ),
			'headers'        => $headers,
			'body'           => is_scalar( $body ) ? (string) $body : '',
			'timeout'        => $args['timeout'] ?? null,
			'args'           => $args,
		);
		$this->calls[] = $call;

		list( $status, $body ) = call_user_func( $this->responder, $call );

		return array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => (int) $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Whether the URL has the base's scheme, host and port (not merely its prefix).
	 *
	 * @param string $url URL.
	 */
	private function is_ours( string $url ): bool {
		$ours   = wp_parse_url( $this->base );
		$theirs = wp_parse_url( $url );

		if ( ! is_array( $theirs ) || ! isset( $theirs['scheme'], $theirs['host'] ) ) {
			return false;
		}

		$port = static function ( array $parts ) {
			return $parts['port'] ?? ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );
		};

		return strtolower( $ours['scheme'] ) === strtolower( $theirs['scheme'] )
			&& strtolower( $ours['host'] ) === strtolower( $theirs['host'] )
			&& $port( $ours ) === $port( $theirs );
	}

	/**
	 * Recorded calls.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function calls(): array {
		return $this->calls;
	}
}
