<?php
/**
 * The Django client and the customer error allow-list.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftServices\Http\Connections;
use OdooWooPortal\Context;
use OdooWooPortal\Django\Client;
use OdooWooPortal\Django\Messages;
use OdooWooPortal\Django\Reply;
use OdooWooPortal\Settings;

/**
 * Signing, URL safety, secret stripping and which error texts reach the browser.
 */
final class ClientTest extends TestCase {

	/**
	 * Lines logged during the test.
	 *
	 * @var string[]
	 */
	private $logged = array();

	public function set_up(): void {
		parent::set_up();
		Context::reset();
		$this->logged   = array();
		$this->captured = array();
		add_action( 'odoowoo_portal_log', fn( $line ) => $this->logged[] = $line );
		// Keep error_log() out of the test output.
		$this->previous_log = ini_set( 'error_log', sys_get_temp_dir() . '/odoowoo-portal-tests.log' );
	}

	/**
	 * Previous error_log setting.
	 *
	 * @var string|false
	 */
	private $previous_log = false;

	public function tear_down(): void {
		ini_set( 'error_log', false === $this->previous_log ? '' : $this->previous_log );
		remove_all_actions( 'odoowoo_portal_log' );
		parent::tear_down();
	}

	/**
	 * A ready, owned license: its context and tenant.
	 *
	 * @return array{0: Context, 1: string}
	 */
	private function ready(): array {
		$license = $this->odoowoo_license();
		$ctx     = Context::for_user( (int) $license['user_id'], (int) $license['id'] );
		$this->assertNotNull( $ctx );

		return array( $ctx, $ctx->tenant() );
	}

	private function use_base_url( string $url ): void {
		$connection             = Connections::all()['odoowoo'];
		$connection['base_url'] = $url;
		$connection['secret']   = self::SECRET;
		$this->assertTrue( Connections::save( 'odoowoo', $connection ), 'Connection saved.' );
	}

	/**
	 * Answers 200 to a host the shared fake does not serve, and records the arguments.
	 *
	 * @param string $host Host to answer for.
	 */
	private function capture( string $host ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $host ) {
				if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
					return $pre;
				}
				$this->captured[] = array( 'url' => $url, 'args' => $args );

				return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			},
			10,
			3
		);
	}

	/**
	 * Requests seen by capture().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $captured = array();

	public function test_request_is_signed_over_the_exact_body(): void {
		$this->connection();
		list( $ctx, $tenant ) = $this->ready();
		$this->fake_django( static fn() => array( 200, array( 'ok' => true ) ) );

		$reply = Client::request( $ctx, 'POST', 'dashboards/9/woo-keys/', array( 'consumer_key' => 'ck_é/1' ), array( 'a' => 'x y', 'b' => 2 ) );

		$this->assertTrue( $reply->ok() );
		$calls = $this->django_calls();
		$this->assertCount( 1, $calls );
		$call = $calls[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( '/api/dashboards/9/woo-keys/?a=x%20y&b=2', $call['path_and_query'] );
		$this->assertSame( wp_json_encode( array( 'consumer_key' => 'ck_é/1' ) ), $call['body'] );
		$this->assert_signed( $call, $tenant );
		$this->assertSame( 15, (int) $call['timeout'], "The connection's own timeout." );
		$this->assertSame( 0, $call['args']['redirection'] );
		$this->assertTrue( $call['args']['sslverify'] );

		// A tampered body no longer verifies.
		$call['body'] .= ' ';
		$headers       = array_change_key_case( $call['headers'], CASE_LOWER );
		$this->assertNotSame(
			\BeltoftServices\Http\Signer::signature( self::SECRET, 'POST', $call['path_and_query'], $headers['x-beltoft-timestamp'], $tenant, $call['body'] ),
			$headers['x-beltoft-signature']
		);
	}

	public function test_get_has_no_body_and_signs_the_empty_body(): void {
		$this->connection();
		list( $ctx, $tenant ) = $this->ready();
		$this->fake_django( static fn() => array( 200, array() ) );

		Client::request( $ctx, 'GET', 'dashboards/' );

		$call = $this->django_calls()[0];
		$this->assertSame( '', $call['body'] );
		$this->assertEmpty( $call['args']['body'] ?? null );
		$this->assert_signed( $call, $tenant );
	}

	public function test_uses_the_connection_not_a_stored_secret(): void {
		list( $ctx ) = $this->ready();
		$this->fake_django( static fn() => array( 200, array() ) );

		$reply = Client::request( $ctx, 'GET', 'dashboards/' );

		$this->assertFalse( $reply->ok() );
		$this->assertSame( array(), $this->django_calls() );
		$this->assertSame( Messages::GENERIC, Messages::from_reply( $reply )['message'] );

		$this->connection();
		$_POST = array();
		update_option( Settings::OPTION, Settings::sanitize( get_option( Settings::OPTION ) + array( 'secret' => 'x' ) ) );
		$this->assertArrayNotHasKey( 'secret', (array) get_option( Settings::OPTION ) );
	}

	public function test_no_call_without_a_callable_tenant(): void {
		$this->connection();
		$license = $this->odoowoo_license( array(), 'provisioning' );
		$ctx     = Context::for_user( (int) $license['user_id'], (int) $license['id'] );
		$this->fake_django( static fn() => array( 200, array() ) );

		$this->assertFalse( Client::request( $ctx, 'GET', 'dashboards/' )->ok() );
		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_reply_strips_secret_keys_at_any_depth(): void {
		$this->connection();
		list( $ctx ) = $this->ready();
		$this->fake_django(
			static fn() => array(
				200,
				array(
					'webhook_secret'     => 'a',
					'has_webhook_secret' => true,
					'consumer_key'       => 'ck_1',
					'consumer_secret'    => 'b',
					'odoo_password'      => 'c',
					'middleware_api_key' => 'd',
					'results'            => array(
						array( 'id' => 1, 'consumer_secret' => 'e', 'consumer_key' => 'ck_2', 'inner' => array( 'odoo_password' => 'f' ) ),
					),
				),
			)
		);

		$data = Client::request( $ctx, 'GET', 'dashboards/' )->data();
		$json = (string) wp_json_encode( $data );

		foreach ( Reply::SECRET_KEYS as $key ) {
			$this->assertStringNotContainsString( '"' . $key . '"', $json );
		}
		$this->assertTrue( $data['has_webhook_secret'] );
		$this->assertSame( 'ck_1', $data['consumer_key'] );
		$this->assertSame( 'ck_2', $data['results'][0]['consumer_key'] );
	}

	public function test_unsafe_connection_url_is_refused_without_http(): void {
		$this->connection();
		list( $ctx ) = $this->ready();
		$this->use_base_url( 'https://odoowoo.example.com' );
		$this->capture( 'odoowoo.example.com' );

		// Stored connection is fine: https public passes.
		$this->assertTrue( Client::request( $ctx, 'GET', 'dashboards/' )->ok() );
		$this->assertCount( 1, $this->captured );

		// A connection supplied through the filter with plain http to a name is refused.
		add_filter(
			'beltoft_services_connection',
			static fn( $c, $id ) => is_array( $c ) ? array_merge( $c, array( 'base_url' => 'http://example.com' ) ) : $c,
			20,
			2
		);
		$reply = Client::request( $ctx, 'GET', 'dashboards/' );
		$this->assertFalse( $reply->ok() );
		$this->assertCount( 1, $this->captured, 'No second call.' );
	}

	public function test_loopback_http_uses_plain_request_and_public_https_strict(): void {
		$this->connection();
		list( $ctx ) = $this->ready();
		$this->fake_django( static fn() => array( 200, array() ) );
		Client::request( $ctx, 'GET', 'dashboards/' );
		$this->assertFalse( (bool) ( $this->django_calls()[0]['args']['reject_unsafe_urls'] ?? false ) );

		$this->use_base_url( 'https://odoowoo.example.com' );
		$this->capture( 'odoowoo.example.com' );
		Client::request( $ctx, 'GET', 'dashboards/' );
		$this->assertTrue( (bool) $this->captured[0]['args']['reject_unsafe_urls'] );
	}

	public function test_empty_204_reply_is_ok(): void {
		$this->connection();
		list( $ctx ) = $this->ready();
		$this->fake_django( static fn() => array( 204, '' ) );

		$reply = Client::request( $ctx, 'DELETE', 'dashboards/9/' );

		$this->assertTrue( $reply->ok() );
		$this->assertSame( 204, $reply->status() );
		$this->assertSame( array(), $reply->data() );
	}

	public function test_customer_error_shape_is_shown(): void {
		$this->connection();
		list( $ctx ) = $this->ready();

		$this->fake_django( static fn() => array( 409, array( 'code' => 'tenant_paused', 'message' => 'Your plan is paused.', 'field' => null ) ) );
		$reply = Client::request( $ctx, 'POST', 'dashboards/' );
		$this->assertTrue( $reply->customer_facing() );
		$this->assertSame( 'Your plan is paused.', $reply->message() );
		$this->assertSame( array( 'code' => 'tenant_paused', 'message' => 'Your plan is paused.' ), Messages::from_reply( $reply ) );

		$this->fake_django( static fn() => array( 400, array( 'code' => 'invalid_value', 'message' => 'Bad.', 'field' => 'limit' ) ) );
		$reply = Client::request( $ctx, 'POST', 'dashboards/' );
		$this->assertSame( 'limit', $reply->field() );
		$this->assertSame( array( 'code' => 'invalid_value', 'message' => 'Bad.', 'field' => 'limit' ), Messages::from_reply( $reply ) );
	}

	/**
	 * @return array<string, array{0: int|string, 1: mixed, 2: string}>
	 */
	public static function unusable_replies(): array {
		return array(
			'error key'         => array( 500, array( 'error' => 'Traceback boom' ), 'Traceback boom' ),
			'detail key'        => array( 404, array( 'detail' => 'Not found.' ), 'Not found.' ),
			'html 502'          => array( 502, '<html><body>Bad gateway nginx-secret-host</body></html>', 'nginx-secret-host' ),
			'empty 500'         => array( 500, '', 'zzz-none' ),
			'invalid json'      => array( 400, '{"code": "x", "mess', 'x", "mess' ),
			'code only'         => array( 400, array( 'code' => 'x', 'detail' => 'raw-detail-text' ), 'raw-detail-text' ),
			'non-string code'   => array( 400, array( 'code' => 5, 'message' => 'raw-number-code' ), 'raw-number-code' ),
			'success non json'  => array( 200, 'plain raw text', 'plain raw text' ),
			'redirect'          => array( 302, array( 'code' => 'x', 'message' => 'raw-redirect' ), 'raw-redirect' ),
		);
	}

	/**
	 * @dataProvider unusable_replies
	 *
	 * @param int          $status Status.
	 * @param array|string $body   Body.
	 * @param string       $raw    Text that must not reach the browser.
	 */
	public function test_unusable_replies_all_become_the_generic_message( int $status, $body, string $raw ): void {
		$this->connection();
		list( $ctx ) = $this->ready();
		$this->fake_django( static fn() => array( $status, $body ) );

		$reply = Client::request( $ctx, 'GET', 'dashboards/' );
		$error = Messages::from_reply( $reply );

		$this->assertFalse( $reply->ok() );
		$this->assertFalse( $reply->customer_facing() );
		$this->assertSame( Messages::GENERIC, $error['message'] );
		$this->assertStringNotContainsString( $raw, (string) wp_json_encode( $error ) );
		$this->assertCount( 1, $this->logged );

		if ( 'zzz-none' !== $raw ) {
			$this->assertStringContainsString( $raw, $this->logged[0], 'The raw text is logged.' );
		}
	}

	public function test_transport_error_is_unreachable_and_logged(): void {
		$this->connection();
		list( $ctx ) = $this->ready();
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: timed out secret-host' ), 5 );

		$reply = Client::request( $ctx, 'GET', 'dashboards/' );
		$error = Messages::from_reply( $reply );

		$this->assertSame( 0, $reply->status() );
		$this->assertSame( Messages::UNREACHABLE, $error['message'] );
		$this->assertStringNotContainsString( 'secret-host', (string) wp_json_encode( $error ) );
		$this->assertStringContainsString( 'secret-host', $this->logged[0] );
	}

	public function test_odoowoo_mistake_error_details_never_shown(): void {
		$this->connection();
		list( $ctx ) = $this->ready();

		// Shown: it came as {code, message}.
		$this->fake_django( static fn() => array( 500, array( 'code' => 'server_error', 'message' => 'Something went wrong on our side. Please try again later.' ) ) );
		$shown = Messages::from_reply( Client::request( $ctx, 'GET', 'dashboards/' ) );
		$this->assertSame( 'server_error', $shown['code'] );

		// Not shown: the same kind of failure in an {error} body.
		$this->fake_django( static fn() => array( 500, array( 'error' => 'ValueError: invalid literal for int()' ) ) );
		$hidden = Messages::from_reply( Client::request( $ctx, 'GET', 'dashboards/' ) );
		$this->assertSame( Messages::GENERIC, $hidden['message'] );
		$this->assertStringNotContainsString( 'ValueError', (string) wp_json_encode( $hidden ) );
	}

	public function test_a_success_body_with_code_and_message_is_not_an_error(): void {
		$this->connection();
		list( $ctx ) = $this->ready();
		$this->fake_django( static fn() => array( 200, array( 'code' => 'x', 'message' => 'hello' ) ) );

		$reply = Client::request( $ctx, 'GET', 'dashboards/' );
		$this->assertTrue( $reply->ok() );
		$this->assertFalse( $reply->customer_facing() );
	}
}
