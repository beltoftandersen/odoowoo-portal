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
	 * Recorded calls: method, path_and_query, headers, body.
	 *
	 * @var array<int, array{method: string, path_and_query: string, headers: array<string, string>, body: string}>
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
		if ( 0 !== strpos( $url, $this->base ) ) {
			return $preempt;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$qs   = wp_parse_url( $url, PHP_URL_QUERY );

		$headers = array();
		foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
			$headers[ (string) $name ] = (string) $value;
		}

		$call          = array(
			'method'         => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'path_and_query' => $path . ( is_string( $qs ) && '' !== $qs ? '?' . $qs : '' ),
			'headers'        => $headers,
			'body'           => (string) ( $args['body'] ?? '' ),
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
	 * Recorded calls.
	 *
	 * @return array<int, array{method: string, path_and_query: string, headers: array<string, string>, body: string}>
	 */
	public function calls(): array {
		return $this->calls;
	}
}
