<?php
/**
 * REST helpers for the route tests.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftServices\Resource;
use OdooWooPortal\Api\Routes;
use OdooWooPortal\Context;

/**
 * A REST server with the portal routes, and a fake Django that answers by "METHOD path".
 */
abstract class RestTestCase extends TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		Context::reset();
		Routes::reset();
		$this->connection();
		ini_set( 'error_log', sys_get_temp_dir() . '/odoowoo-portal-tests.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * The tenant of a license.
	 *
	 * @param array<string, mixed> $license License.
	 */
	protected function tenant( array $license ): string {
		return (string) Resource::for_license( (int) $license['id'] )->get( 'uuid' );
	}

	/**
	 * Fake Django answering from a map "METHOD path" => [status, body]; unknown paths are 500.
	 *
	 * @param array<string, array{0: int, 1: mixed}> $map Answers.
	 */
	protected function django_map( array $map ): void {
		$this->fake_django(
			function ( $call ) use ( $map ) {
				$path = preg_replace( '#^/api/#', '', strtok( $call['path_and_query'], '?' ) );

				return $map[ $call['method'] . ' ' . $path ] ?? array( 500, 'unexpected ' . $call['method'] . ' ' . $path );
			}
		);
	}

	/**
	 * Sends a request through the REST server.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route under the namespace.
	 * @param array<string, mixed> $body   JSON body.
	 */
	protected function rest( string $method, string $route, ?array $body = null ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/' . Routes::NAMESPACE . $route );

		if ( null !== $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $req );
	}
}
