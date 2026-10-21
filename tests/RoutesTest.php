<?php
/**
 * Dashboard routes and the license summary.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Routes;
use OdooWooPortal\Context;

/**
 * Create, detail, rename, pause, delete, and what the app reads per license.
 */
final class RoutesTest extends RestTestCase {

	public function test_create_posts_to_dashboards_create_and_forwards_django_limit_error(): void {
		$l = $this->odoowoo_license();
		$this->fake_django( fn() => array( 403, array( 'code' => 'dashboard_limit_reached', 'message' => 'You have reached your plan limit of 1 dashboard.', 'limit' => 1 ) ) );
		$this->as_user( (int) $l['user_id'] );

		$res = $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => '  Shop  ', 'tenant' => 'evil', 'license' => 9 ) );
		$call = $this->django_calls()[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( '/api/dashboards/create/', $call['path_and_query'] );
		$this->assertSame( array( 'name' => 'Shop' ), json_decode( $call['body'], true ) );
		$this->assert_signed( $call, $this->tenant( $l ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'dashboard_limit_reached', $res->get_data()['code'] );
	}

	public function test_odoowoo_mistake_limit_enforced_by_django_not_the_ui(): void {
		$l = $this->odoowoo_license();
		$this->django_map(
			array(
				'GET tenants/' . $this->tenant( $l ) . '/' => array( 200, array( 'limit' => 1, 'used' => 1, 'attributes' => array() ) ),
				'GET dashboards/'                          => array( 200, array( array( 'id' => 1, 'name' => 'A' ) ) ),
				'POST dashboards/create/'                  => array( 403, array( 'code' => 'dashboard_limit_reached', 'message' => 'You have reached your plan limit of 1 dashboard.' ) ),
			)
		);
		$this->as_user( (int) $l['user_id'] );
		$summary = Routes::license_summary( Context::for_user( (int) $l['user_id'], (int) $l['id'] ) );
		$this->assertGreaterThanOrEqual( $summary['limit'], $summary['used'] );

		$res = $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'B' ) );

		$this->assertSame( 'POST dashboards/create/', $this->django_calls()[2]['method'] . ' ' . substr( $this->django_calls()[2]['path_and_query'], 5 ) );
		$this->assertSame( 'You have reached your plan limit of 1 dashboard.', $res->get_data()['message'] );
	}

	public function test_create_success_and_garbage(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 201, array( 'id' => 4, 'name' => 'B' ) ) );
		$res = $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'B' ) );
		$this->assertSame( array( 'ok' => true, 'data' => array( 'id' => 4, 'name' => 'B' ) ), $res->get_data() );

		foreach ( array( array( 502, '<html>bad gateway</html>' ), array( 400, array( 'error' => 'raw' ) ), array( 200, 'not json' ) ) as $answer ) {
			$this->fake_django( fn() => $answer );
			$res = $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards', array( 'name' => 'B' ) );
			$this->assertSame( 502, $res->get_status() );
			$this->assertFalse( $res->get_data()['ok'] );
			$this->assertStringNotContainsString( 'raw', wp_json_encode( $res->get_data() ) );
			$this->assertStringNotContainsString( 'bad gateway', wp_json_encode( $res->get_data() ) );
		}
	}

	public function test_body_and_query_cannot_override_license_or_dashboard(): void {
		$mine   = $this->odoowoo_license();
		$theirs = $this->odoowoo_license();
		$this->fake_django( fn() => array( 200, array( 'id' => 5 ) ) );
		$this->as_user( (int) $mine['user_id'] );

		$req = new \WP_REST_Request( 'PATCH', '/' . Routes::NAMESPACE . '/licenses/' . $mine['id'] . '/dashboards/5' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( array( 'name' => 'x', 'license' => $theirs['id'], 'dashboard' => 99 ) ) );
		$req->set_query_params( array( 'license' => $theirs['id'], 'dashboard' => 98 ) );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '/api/dashboards/5/', $this->django_calls()[0]['path_and_query'] );
		$this->assert_signed( $this->django_calls()[0], $this->tenant( $mine ) );
	}

	public function test_rename_patches_name_only(): void {
		$l = $this->odoowoo_license();
		$this->fake_django( fn() => array( 200, array( 'id' => 5, 'name' => 'N' ) ) );
		$this->as_user( (int) $l['user_id'] );

		$res  = $this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/5', array( 'name' => ' N ', 'tenant' => 'x', 'id' => 8 ) );
		$call = $this->django_calls()[0];

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'PATCH', $call['method'] );
		$this->assertSame( '/api/dashboards/5/', $call['path_and_query'] );
		$this->assertSame( array( 'name' => 'N' ), json_decode( $call['body'], true ) );

		// Pause sends enabled only, as a bool; an empty change is refused without a call.
		$this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/5', array( 'enabled' => false ) );
		$this->assertSame( array( 'enabled' => false ), json_decode( $this->django_calls()[1]['body'], true ) );
		$res = $this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/5', array( 'other' => 1 ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertCount( 2, $this->django_calls() );
	}

	public function test_delete_calls_dashboard_delete_and_forgets_tasks(): void {
		$l = $this->odoowoo_license();
		$this->fake_django( fn() => array( 204, '' ) );
		$this->as_user( (int) $l['user_id'] );
		$forgot = array();
		add_action( 'odoowoo_portal_dashboard_deleted', function ( $lic, $d ) use ( &$forgot ) {
			$forgot = array( $lic, $d );
		}, 10, 2 );

		$res  = $this->rest( 'DELETE', '/licenses/' . $l['id'] . '/dashboards/5' );
		$call = $this->django_calls()[0];

		$this->assertSame( 'DELETE', $call['method'] );
		$this->assertSame( '/api/dashboards/5/delete/', $call['path_and_query'] );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( array( (int) $l['id'], 5 ), $forgot );
	}

	public function test_license_summary_is_one_django_read_per_request(): void {
		$l = $this->odoowoo_license();
		$this->django_map(
			array(
				'GET tenants/' . $this->tenant( $l ) . '/' => array( 200, array( 'limit' => 3, 'used' => 1, 'paused' => false, 'attributes' => array( 'allow_brand_sync' => true ) ) ),
				'GET dashboards/'                          => array( 200, array( array( 'id' => 1, 'name' => 'A', 'enabled' => true ) ) ),
			)
		);
		$ctx = Context::for_user( (int) $l['user_id'], (int) $l['id'] );

		$a = Routes::license_summary( $ctx );
		$b = Routes::license_summary( $ctx );

		$this->assertSame( $a, $b );
		$this->assertCount( 2, $this->django_calls() );
		$this->assertSame( 3, $a['limit'] );
		$this->assertSame( 1, $a['used'] );
		$this->assertTrue( $a['brand_sync'] );
		$this->assertTrue( $a['can_write'] );
		$this->assertSame( 'ready', $a['state'] );
		$this->assertSame( 'owner', $a['mode'] );
		$this->assertSame( 1, $a['dashboards'][0]['id'] );
	}

	public function test_getting_ready_license_makes_no_django_call(): void {
		$l = $this->odoowoo_license( array(), 'provisioning' );
		$this->fake_django( fn() => array( 200, array() ) );
		$s = Routes::license_summary( Context::for_user( (int) $l['user_id'], (int) $l['id'] ) );

		$this->assertSame( 'getting-ready', $s['state'] );
		$this->assertNull( $s['limit'] );
		$this->assertNull( $s['used'] );
		$this->assertFalse( $s['brand_sync'] );
		$this->assertSame( array(), $s['dashboards'] );
		$this->assertCount( 0, $this->django_calls() );
	}

	public function test_licenses_route_lists_per_license_usage_and_groups(): void {
		$user = self::factory()->user->create();
		$one  = $this->odoowoo_license( array( 'user_id' => $user ) );
		$two  = $this->odoowoo_license( array( 'user_id' => $user ) );
		$this->odoowoo_license(); // Someone else's.
		$this->fake_django(
			function ( $call ) use ( $one, $two ) {
				$t = $call['headers']['X-Beltoft-Tenant'];
				if ( str_contains( $call['path_and_query'], 'tenants/' ) ) {
					return $t === $this->tenant( $one ) ? array( 200, array( 'limit' => 1, 'used' => 1, 'attributes' => array() ) ) : array( 200, array( 'limit' => 5, 'used' => 2, 'attributes' => array() ) );
				}
				return array( 200, array( array( 'id' => $t === $this->tenant( $one ) ? 11 : 22 ) ) );
			}
		);
		$this->as_user( $user );

		$data = $this->rest( 'GET', '/licenses' )->get_data()['data'];
		$by   = array_column( $data, null, 'id' );

		$this->assertCount( 2, $data );
		$this->assertSame( array( 1, 1, 11 ), array( $by[ $one['id'] ]['limit'], $by[ $one['id'] ]['used'], $by[ $one['id'] ]['dashboards'][0]['id'] ) );
		$this->assertSame( array( 5, 2, 22 ), array( $by[ $two['id'] ]['limit'], $by[ $two['id'] ]['used'], $by[ $two['id'] ]['dashboards'][0]['id'] ) );
	}

	public function test_dot_segments_and_task_suffixes_never_reach_django(): void {
		$l = $this->odoowoo_license();
		$this->fake_django( fn() => array( 200, array( 'x' => 1 ) ) );
		$this->as_user( (int) $l['user_id'] );
		$base = '/licenses/' . $l['id'] . '/dashboards/5/verify-odoo';

		foreach ( array( '/..', '/.', '/%2e%2e', '/a.b', '/..%2f..' ) as $suffix ) {
			$res = $this->rest( 'POST', $base . $suffix, array( 'odoo_url' => 'u' ) );
			$this->assertGreaterThanOrEqual( 400, $res->get_status(), $suffix );
		}

		// A task suffix on a row that has no task path is refused.
		$res = $this->rest( 'POST', $base . '/abc-123', array( 'odoo_url' => 'u' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertCount( 0, $this->django_calls() );

		// The client itself refuses odd paths.
		$ctx = Context::for_user( (int) $l['user_id'], (int) $l['id'] );
		foreach ( array( 'dashboards/5/../../tenants/x/', 'a/./b', 'a?x=1', 'a#f', 'a//b', 'a/%2e%2e/b', 'a/%252e%252e/b', 'a/%2E%2E/b', '' ) as $path ) {
			$r = \OdooWooPortal\Django\Client::request( $ctx, 'GET', $path );
			$this->assertFalse( $r->ok(), $path );
		}
		$this->assertCount( 0, $this->django_calls() );
	}

	public function test_guard_refusals_before_rate_limit_and_status_mapping(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, array() ) );

		// Wrong method: 405, no slot consumed.
		$this->assertSame( 405, $this->rest( 'GET', '/licenses/' . $l['id'] . '/dashboards/5/verify-odoo' )->get_status() );
		$this->assertFalse( \BeltoftServices\RateLimit::exceeded( (int) $l['user_id'], 'odoowoo-portal:verify', 1 ), 'First counted call is the first.' );

		// Dashboard id 0.
		$this->assertSame( 400, $this->rest( 'GET', '/licenses/' . $l['id'] . '/dashboards/0' )->get_status() );
		$this->assertCount( 0, $this->django_calls() );

		// Django 401/403/409 with a customer error: never 401/403 to the browser.
		foreach ( array( 401 => 401, 403 => 403, 409 => 409 ) as $django => $expect ) {
			$this->fake_django( fn() => array( $django, array( 'code' => 'some_code', 'message' => 'Shown.' ) ) );
			$res = $this->rest( 'PATCH', '/licenses/' . $l['id'] . '/dashboards/5', array( 'name' => 'n' ) );
			$this->assertSame( 409, $res->get_status() );
			$this->assertSame( 'some_code', $res->get_data()['code'] );
			$this->assertSame( 'Shown.', $res->get_data()['message'] );
		}
	}

	public function test_failed_tenant_read_sets_error_and_delete_data_is_an_object(): void {
		$l = $this->odoowoo_license();
		$this->fake_django(
			fn( $call ) => str_contains( $call['path_and_query'], 'tenants/' ) ? array( 502, '<html>' ) : array( 200, array() )
		);
		$s = Routes::license_summary( Context::for_user( (int) $l['user_id'], (int) $l['id'] ) );
		$this->assertNull( $s['limit'] );
		$this->assertNotEmpty( $s['error'] );
		$this->assertStringNotContainsString( 'html', $s['error'] );

		$this->fake_django( fn() => array( 204, '' ) );
		$this->as_user( (int) $l['user_id'] );
		$res = $this->rest( 'DELETE', '/licenses/' . $l['id'] . '/dashboards/5' );
		$this->assertSame( '{"ok":true,"data":{}}', wp_json_encode( $res->get_data() ) );
	}

	public function test_empty_list_stays_a_list_and_suffix_refusal_uses_no_rate_slot(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 200, '[]' ) );
		$res = $this->rest( 'GET', '/licenses/' . $l['id'] . '/dashboards/5' );
		$this->assertSame( '{"ok":true,"data":[]}', wp_json_encode( $res->get_data() ) );

		$this->assertSame( 400, $this->rest( 'POST', '/licenses/' . $l['id'] . '/dashboards/5/verify-odoo/abc', array( 'odoo_url' => 'u' ) )->get_status() );
		$this->assertFalse( \BeltoftServices\RateLimit::exceeded( (int) $l['user_id'], 'odoowoo-portal:verify', 1 ), 'No slot was used.' );
	}
}
