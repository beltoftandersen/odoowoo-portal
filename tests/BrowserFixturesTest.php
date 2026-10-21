<?php
/**
 * The browser replies the JS tests are written against, produced by the real routes.
 *
 * Every fixture is {status, body}: what the REST server really answers for a faked Django reply
 * (each cited from the Django view or the test that cites it). A test fails when a file in
 * tests/js/fixtures differs from what the routes answer now. To regenerate after a deliberate
 * route change:
 *
 *   ODOOWOO_REGEN_FIXTURES=1 /root/plugin-tests/run.sh <plugin dir> --filter BrowserFixturesTest
 *
 * then run the node tests and commit the changed files.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Tasks;

/**
 * Generates and checks tests/js/fixtures/*.json.
 */
final class BrowserFixturesTest extends RestTestCase {

	private const D = 7;

	/**
	 * Fixtures made so far: name => {status, body}.
	 *
	 * @var array<string, array{status: int, body: mixed}>
	 */
	private $made = array();

	private function url( array $l, string $route ): string {
		return '/licenses/' . $l['id'] . '/dashboards/' . self::D . '/' . $route;
	}

	/**
	 * Records a reply as a fixture.
	 *
	 * @param string            $name Name.
	 * @param \WP_REST_Response $res  Reply.
	 */
	private function keep( string $name, \WP_REST_Response $res ): void {
		$this->made[ $name ] = array(
			'status' => $res->get_status(),
			'body'   => json_decode( (string) wp_json_encode( $res->get_data() ), false ),
		);
	}

	/**
	 * A task reply through the status route.
	 *
	 * @param string               $name  Fixture name.
	 * @param array<string, mixed> $l     License.
	 * @param array<string, mixed> $reply Django reply.
	 * @param int                  $code  Django status.
	 */
	private function task( string $name, array $l, array $reply, int $code = 200 ): void {
		Tasks::remember( (int) $l['user_id'], (int) $l['id'], self::D, 'products', 'tk-1' );
		$this->fake_django( fn() => array( $code, $reply ) );
		$this->keep( $name, $this->rest( 'GET', $this->url( $l, 'task-status/tk-1' ) ) );
	}

	public function test_browser_fixtures_match_the_real_routes(): void {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );

		// A list: Django odoo-journals answers a bare list (sync_app/views.py:849).
		$this->fake_django( fn() => array( 200, array( array( 'id' => 1, 'name' => 'Bank' ), array( 'id' => 2, 'name' => 'Cash' ) ) ) );
		$this->keep( 'list_success', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Settings: woo-keys GET (sync_app/views.py:261-285 fields; secrets are only has_* flags).
		$this->fake_django( fn() => array( 200, array( 'consumer_key' => 'ck_1', 'has_consumer_secret' => true, 'has_webhook_secret' => true, 'woocommerce_url' => 'https://shop.example', 'prefix' => 'WS', 'enable_stock_update' => true, 'change_status_delivery' => false, 'used_prefixes' => array( 'WS' ) ) ) );
		$this->keep( 'settings_success', $this->rest( 'GET', $this->url( $l, 'woo-keys' ) ) );

		// A save: Django answers a message (ConnectionRoutesTest, views.py woo-keys POST).
		$this->fake_django( fn() => array( 200, array( 'message' => 'WooCommerce keys updated successfully.' ) ) );
		$this->keep( 'save_success', $this->rest( 'PUT', $this->url( $l, 'woo-keys' ), array( 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_dummy', 'woocommerce_url' => 'https://shop.example', 'prefix' => 'WS' ) ) );

		// A Django field error (the {code, message, field} contract).
		$this->fake_django( fn() => array( 400, array( 'code' => 'invalid_request', 'message' => 'Expected a \'mappings\' list.', 'field' => 'mappings' ) ) ); // Views.py:921, 1090.
		$this->keep( 'refusal_field', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Not configured (views.py:110, 125).
		$this->fake_django( fn() => array( 400, array( 'code' => 'odoo_not_configured', 'message' => 'Save your Odoo connection settings first.' ) ) );
		$this->keep( 'not_configured', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Django itself unusable: HTML from a proxy.
		$this->fake_django( fn() => array( 502, '<html>Bad gateway</html>' ) );
		$this->keep( 'django_garbage', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Tasks (product/views.py:14 task_not_found; task shapes as in SyncRoutesTest).
		$base = array( 'task_id' => 'tk-1', 'status' => 'PROGRESS', 'state' => 'running', 'message' => 'Task is progress.', 'results' => array(), 'progress' => array( 'current' => 0, 'total' => 0 ), 'current' => 0, 'total' => 0 );
		$this->task( 'task_running_start', $l, $base );
		$this->task( 'task_running_1_of_10', $l, array_merge( $base, array( 'current' => 1, 'total' => 10 ) ) );
		$this->task( 'task_running_2_of_10', $l, array_merge( $base, array( 'current' => 2, 'total' => 10 ) ) );
		$this->task( 'task_running_30_of_120', $l, array_merge( $base, array( 'current' => 30, 'total' => 120 ) ) );
		$this->task( 'task_done', $l, array_merge( $base, array( 'status' => 'SUCCESS', 'state' => 'done', 'message' => 'Done.', 'current' => 5, 'total' => 5 ) ) );
		$this->task( 'task_skipped', $l, array_merge( $base, array( 'status' => 'SKIPPED', 'state' => 'skipped', 'message' => 'Sync categories is deactivated.' ) ) );
		$this->task( 'task_failed', $l, array_merge( $base, array( 'status' => 'FAILED', 'state' => 'failed', 'message' => 'The sync failed. See the sync log for details.' ) ) );
		$this->task( 'task_django_not_found', $l, array( 'code' => 'task_not_found', 'message' => 'This sync was not found. It may have finished more than a day ago.' ), 404 );
		$this->task( 'task_unavailable', $l, array( 'code' => 'task_status_unavailable', 'message' => 'The sync status could not be read. Try again in a moment.' ), 500 );

		$this->fake_django( fn() => array( 200, array() ) );
		$res = $this->rest( 'GET', $this->url( $l, 'task-status/never-started' ) );
		$this->keep( 'task_unknown', $res );

		// Django 401/403 arrive as 409 (never a session error): sync_app/middleware.py:13,50 and views.py:671
		// (BRANDS_NOT_IN_PLAN, tests/test_limits.py:33).
		$this->fake_django( fn() => array( 401, array( 'code' => 'unauthorized', 'message' => 'The request could not be authenticated.' ) ) );
		$this->keep( 'django_401', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );
		$this->fake_django( fn() => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ) ) );
		$this->keep( 'django_403', $this->rest( 'GET', $this->url( $l, 'odoo-journals' ) ) );

		// Read-only: a paused owner tries to save.
		$p = $this->odoowoo_license( array(), 'paused' );
		$this->as_user( (int) $p['user_id'] );
		$this->keep( 'refusal_read_only', $this->rest( 'POST', '/licenses/' . $p['id'] . '/dashboards', array( 'name' => 'n' ) ) );

		// Session: logged out (the portal's own permission callback).
		wp_set_current_user( 0 );
		$this->keep( 'session_login', $this->rest( 'GET', '/licenses' ) );

		// Session: WordPress' own cookie check with a bad nonce (rest_cookie_check_errors, rest-api.php).
		$this->as_user( (int) $l['user_id'] );
		$_SERVER['HTTP_X_WP_NONCE'] = 'badbadbad';
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'x';
		global $wp_rest_auth_cookie;
		$wp_rest_auth_cookie = true;
		$error               = rest_cookie_check_errors( null );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_COOKIE[ LOGGED_IN_COOKIE ] );
		$wp_rest_auth_cookie = null;
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->keep( 'session_nonce', rest_convert_error_to_response( $error ) );

		$dir = __DIR__ . '/js/fixtures';

		foreach ( $this->made as $name => $fixture ) {
			$json = wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
			$file = $dir . '/' . $name . '.json';

			if ( getenv( 'ODOOWOO_REGEN_FIXTURES' ) ) {
				file_put_contents( $file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			$this->assertFileExists( $file, $name . ': run with ODOOWOO_REGEN_FIXTURES=1 to create it.' );
			$this->assertSame( $json, file_get_contents( $file ), $name . ' is stale: regenerate with ODOOWOO_REGEN_FIXTURES=1 (see this file\'s header).' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		// No file without a test: a leftover fixture is a stale one.
		$files = array_map( static fn( $f ) => basename( $f, '.json' ), glob( $dir . '/*.json' ) ?: array() );
		sort( $files );
		$names = array_keys( $this->made );
		sort( $names );
		$this->assertSame( $names, $files, 'Every fixture file is produced by this test.' );
	}
}
