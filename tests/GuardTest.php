<?php
/**
 * The REST guard.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Map;
use OdooWooPortal\Django\Messages;

/**
 * Login, nonce, ownership, state, read-only and rate limits.
 */
final class GuardTest extends RestTestCase {

	/**
	 * Every browser route as [method, route suffix after /licenses/{l}/dashboards/{d}].
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function all_routes( int $l, int $d ): array {
		$routes = array(
			array( 'GET', '/licenses' ),
			array( 'POST', "/licenses/$l/dashboards" ),
			array( 'GET', "/licenses/$l/dashboards/$d" ),
			array( 'PATCH', "/licenses/$l/dashboards/$d" ),
			array( 'DELETE', "/licenses/$l/dashboards/$d" ),
		);

		foreach ( Map::generic_keys() as $key ) {
			foreach ( array_keys( Map::ROUTES[ $key ]['methods'] ) as $method ) {
				$routes[] = array( $method, "/licenses/$l/dashboards/$d/$key" );
			}
		}

		return $routes;
	}

	public function test_every_route_requires_login_and_nonce(): void {
		$license = $this->odoowoo_license();
		$this->django_map( array() );
		wp_set_current_user( 0 );

		foreach ( $this->all_routes( (int) $license['id'], 5 ) as $r ) {
			$this->assertSame( 401, $this->rest( $r[0], $r[1] )->get_status(), implode( ' ', $r ) );
		}

		$this->assertSame( array(), $this->django_calls() );

		// rest_do_request skips the cookie check: test the filter WordPress runs.
		$this->as_user( (int) $license['user_id'] );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_REQUEST['_wpnonce'] = '';
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = true;
		$this->assertWPError( apply_filters( 'rest_authentication_errors', null ) );
		unset( $_REQUEST['_wpnonce'] );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$GLOBALS['wp_rest_server'] = new class() extends \WP_REST_Server {
			// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
			public function send_header( $key, $value ) {} // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames
		};
		$this->assertTrue( apply_filters( 'rest_authentication_errors', null ) );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
	}

	public function test_other_users_license_id_is_403_and_makes_no_django_call(): void {
		$mine   = $this->odoowoo_license();
		$theirs = $this->odoowoo_license();
		$this->django_map( array() );
		$this->as_user( (int) $mine['user_id'] );

		foreach ( $this->all_routes( (int) $theirs['id'], 5 ) as $r ) {
			if ( '/licenses' === $r[1] ) {
				continue;
			}
			$res = $this->rest( $r[0], $r[1], 'GET' === $r[0] ? null : array( 'name' => 'x' ) );
			$this->assertSame( 403, $res->get_status(), implode( ' ', $r ) );
			$this->assertSame( 'odoowoo_portal_forbidden', $res->get_data()['code'] );
		}

		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_dashboard_of_other_license_shows_not_found(): void {
		$a = $this->odoowoo_license();
		$b = $this->odoowoo_license();
		$this->django_map(
			array(
				'GET dashboards/77/' => array( 404, array( 'code' => 'dashboard_not_found', 'message' => 'This dashboard was not found.' ) ),
			)
		);
		$this->as_user( (int) $a['user_id'] );

		$res  = $this->rest( 'GET', '/licenses/' . $a['id'] . '/dashboards/77' );
		$data = $res->get_data();

		$this->assertSame( 404, $res->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'dashboard_not_found', $data['code'] );
		$this->assertSame( Messages::not_found(), $data['message'] );
		$this->assertArrayNotHasKey( 'data', $data );
		$this->assertCount( 1, $this->django_calls() );
		$this->assert_signed( $this->django_calls()[0], $this->tenant( $a ) );
		$this->assertNotSame( $this->tenant( $b ), $this->django_calls()[0]['headers']['X-Beltoft-Tenant'] );
	}

	public function test_odoowoo_mistake_unknown_dashboard_shows_not_found_not_empty_forms(): void {
		$a = $this->odoowoo_license();
		$this->as_user( (int) $a['user_id'] );

		foreach ( array( array( 404, '{"detail":"Not found."}' ), array( 404, '' ), array( 404, '<html>' ) ) as $answer ) {
			$this->fake_django( fn() => $answer );
			$res = $this->rest( 'GET', '/licenses/' . $a['id'] . '/dashboards/9' );
			$this->assertFalse( $res->get_data()['ok'] );
			$this->assertSame( 'dashboard_not_found', $res->get_data()['code'] );
			$this->assertSame( 404, $res->get_status() );
		}
	}

	public function test_read_only_matrix(): void {
		$paths = array( 'ok' => array( 200, array( 'x' => 1 ) ) );
		$all   = function () use ( $paths ) {
			$this->fake_django( fn() => $paths['ok'] );
		};
		$write = array(
			array( 'POST', '/dashboards', array( 'name' => 'n' ) ),
			array( 'PATCH', '/dashboards/5', array( 'name' => 'n' ) ),
			array( 'PATCH', '/dashboards/5', array( 'enabled' => false ) ),
		);
		$open  = array(
			array( 'DELETE', '/dashboards/5', null ),
			array( 'POST', '/dashboards/5/verify-woocommerce', array( 'consumer_key' => 'k' ) ),
			array( 'POST', '/dashboards/5/verify-odoo', array( 'odoo_url' => 'u' ) ),
		);

		// Owner, ready: everything passes.
		$ready = $this->odoowoo_license();
		$all();
		$this->as_user( (int) $ready['user_id'] );
		foreach ( array_merge( $write, $open, array( array( 'GET', '/dashboards/5', null ) ) ) as $r ) {
			$this->assertSame( 200, $this->rest( $r[0], '/licenses/' . $ready['id'] . $r[1], $r[2] )->get_status(), $r[0] . $r[1] );
		}

		// Owner, paused (license suspended): writes refused with the notice, open ones and reads pass.
		$paused = $this->odoowoo_license( array(), 'paused' );
		$all();
		$this->as_user( (int) $paused['user_id'] );
		$ctx = \OdooWooPortal\Context::for_user( (int) $paused['user_id'], (int) $paused['id'] );
		foreach ( $write as $r ) {
			$res = $this->rest( $r[0], '/licenses/' . $paused['id'] . $r[1], $r[2] );
			$this->assertSame( 403, $res->get_status() );
			$this->assertSame( 'odoowoo_portal_read_only', $res->get_data()['code'] );
			$this->assertSame( $ctx->notice(), $res->get_data()['message'] );
		}
		foreach ( array_merge( $open, array( array( 'GET', '/dashboards/5', null ) ) ) as $r ) {
			$this->assertSame( 200, $this->rest( $r[0], '/licenses/' . $paused['id'] . $r[1], $r[2] )->get_status(), $r[0] . $r[1] );
		}

		// Support: reads pass, everything else refused.
		$support = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$this->as_user( $support );
		$this->assertTrue( \OdooWooPortal\Support::arrived( array( 'license' => (string) $ready['id'], 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $ready['id'] ) ), $support ) );
		$ctx = \OdooWooPortal\Context::for_user( $support, (int) $ready['id'] );
		$this->assertSame( 'support', $ctx->mode(), 'Support session exists.' );
		foreach ( array_merge( $write, $open ) as $r ) {
			$res = $this->rest( $r[0], '/licenses/' . $ready['id'] . $r[1], $r[2] );
			$this->assertSame( 403, $res->get_status(), $r[0] . $r[1] );
			$this->assertSame( 'odoowoo_portal_read_only', $res->get_data()['code'] );
		}
		$this->assertSame( 200, $this->rest( 'GET', '/licenses/' . $ready['id'] . '/dashboards/5' )->get_status() );

		// Getting ready: nothing, with no Django call.
		$getting = $this->odoowoo_license( array(), 'provisioning' );
		$this->fake_django( fn() => array( 200, array() ) );
		$before = count( $this->django_calls() );
		$this->as_user( (int) $getting['user_id'] );
		$res = $this->rest( 'GET', '/licenses/' . $getting['id'] . '/dashboards/5' );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'odoowoo_portal_getting_ready', $res->get_data()['code'] );
		$this->assertSame( 'Getting ready…', $res->get_data()['message'] );
		$this->assertSame( $before, count( $this->django_calls() ) );
	}

	public function test_rate_limit_returns_429_with_message(): void {
		$l = $this->odoowoo_license();
		$this->fake_django( fn() => array( 200, array( 'success' => true ) ) );
		$this->as_user( (int) $l['user_id'] );
		$verify = '/licenses/' . $l['id'] . '/dashboards/5/verify-odoo';

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( 200, $this->rest( 'POST', $verify, array( 'odoo_url' => 'u' ) )->get_status(), "call $i" );
		}

		$res = $this->rest( 'POST', $verify, array( 'odoo_url' => 'u' ) );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'odoowoo_portal_rate_limited', $res->get_data()['code'] );
		$this->assertSame( 'Too many requests. Please wait a minute and try again.', $res->get_data()['message'] );
		$this->assertCount( 10, $this->django_calls() );

		// The counter is the services limiter's, under our key; create has its own.
		$this->assertTrue( \BeltoftServices\RateLimit::exceeded( (int) $l['user_id'], 'odoowoo-portal:verify', 10 ) );
		$this->assertSame( 200, $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'a' ) )->get_status() );

		// The limit passes through the filter.
		add_filter( 'odoowoo_portal_rate_limit', fn( $limit, $bucket ) => 'create' === $bucket ? 1 : $limit, 10, 2 );
		$this->assertSame( 429, $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'b' ) )->get_status() );
	}
}
