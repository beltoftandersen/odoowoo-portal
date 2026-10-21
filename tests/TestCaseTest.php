<?php
/**
 * The test base itself.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use PHPUnit\Framework\ExpectationFailedException;

/**
 * The fake Django and the signature assertion.
 */
final class TestCaseTest extends TestCase {

	/**
	 * One signed GET through the real signer; returns the recorded call and the tenant.
	 *
	 * @param string $body Body to send (POST when not empty).
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	private function signed_call( string $body = '' ): array {
		$license = $this->odoowoo_license();
		$tenant  = (string) \BeltoftServices\Resource::for_license( (int) $license['id'] )->get( 'uuid' );
		$method  = '' === $body ? 'GET' : 'POST';

		$this->fake_django( static fn( array $call ) => array( 200, array( 'ok' => true ) ) );

		$connection = apply_filters( 'beltoft_services_connection', null, 'odoowoo' );
		$headers    = \BeltoftServices\Http\Signer::headers( $connection, $method, '/api/x?a=1', $body, $tenant );
		wp_remote_request(
			'http://127.0.0.1:8101/api/x?a=1',
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 15,
			)
		);

		$calls = $this->django_calls();
		$this->assertCount( 1, $calls );

		return array( $calls[0], $tenant );
	}

	public function test_call_records_url_timeout_and_args(): void {
		$this->connection();
		list( $call, $tenant ) = $this->signed_call( '{"a":1}' );

		$this->assertSame( 'http://127.0.0.1:8101/api/x?a=1', $call['url'] );
		$this->assertSame( 15, $call['timeout'] );
		$this->assertSame( 'POST', $call['args']['method'] );
		$this->assertSame( '{"a":1}', $call['body'] );
		$this->assert_signed( $call, $tenant );
	}

	public function test_assert_signed_fails_on_wrong_secret_path_or_body(): void {
		$this->connection();
		list( $call, $tenant ) = $this->signed_call( '{"a":1}' );

		$bad = array(
			'wrong secret' => array( $call, 'another-secret-another' ),
			'path'         => array( array_merge( $call, array( 'path_and_query' => '/api/x?a=2' ) ), self::SECRET ),
			'body'         => array( array_merge( $call, array( 'body' => '{"a":2}' ) ), self::SECRET ),
		);

		foreach ( $bad as $why => $case ) {
			try {
				$this->assert_signed( $case[0], $tenant, $case[1] );
				$this->fail( 'Accepted: ' . $why );
			} catch ( ExpectationFailedException $e ) {
				$this->assertStringNotContainsString( 'Accepted', $e->getMessage(), $why );
			}
		}

		try {
			$this->assert_signed( $call, 'other-tenant' );
			$this->fail( 'Accepted a wrong tenant.' );
		} catch ( ExpectationFailedException $e ) {
			$this->assertStringNotContainsString( 'Accepted', $e->getMessage() );
		}
	}

	public function test_assert_signed_follows_the_connections_header_names(): void {
		$this->assertTrue(
			\BeltoftServices\Http\Connections::save(
				'odoowoo',
				array(
					'label'            => 'OdooWoo',
					'base_url'         => 'http://127.0.0.1:8101',
					'auth'             => 'hmac',
					'secret'           => self::SECRET,
					'timeout'          => '15',
					'header_timestamp' => 'X-Custom-Time',
					'header_tenant'    => 'X-Custom-Tenant',
					'header_signature' => 'X-Custom-Sig',
				)
			)
		);
		$this->settings( array( 'connection_id' => 'odoowoo' ) );

		list( $call, $tenant ) = $this->signed_call();

		$names = array_map( 'strtolower', array_keys( $call['headers'] ) );
		$this->assertContains( 'x-custom-sig', $names );
		$this->assertNotContains( 'x-beltoft-signature', $names );
		$this->assert_signed( $call, $tenant );
	}

	public function test_fake_matches_scheme_host_and_port_not_a_prefix(): void {
		$this->fake_django( static fn( array $call ) => array( 200, 'ok' ) );

		$this->assertSame( 200, wp_remote_retrieve_response_code( wp_remote_get( 'http://127.0.0.1:8101/a' ) ) );
		$this->assertCount( 1, $this->django_calls() );

		// A host that merely starts with the base, and another port: not the fake's, so blocked.
		$other = wp_remote_get( 'http://127.0.0.1:81010/a' );
		$this->assertWPError( $other );
		$this->assertSame( 'odoowoo_test_blocked', $other->get_error_code() );
		$this->assertCount( 1, $this->django_calls() );

		$this->acknowledge_blocked_http();
	}

	public function test_unexpected_http_is_blocked_and_fails_the_test(): void {
		$result = wp_remote_get( 'https://example.com/' );

		$this->assertWPError( $result );
		$this->assertSame( 'odoowoo_test_blocked', $result->get_error_code() );
		$this->assertCount( 0, $this->django_calls() );

		$this->acknowledge_blocked_http();
	}

	public function test_non_string_body_is_never_recorded_as_array(): void {
		$this->fake_django( static fn( array $call ) => array( 200, 'ok' ) );

		wp_remote_post( 'http://127.0.0.1:8101/form', array( 'body' => array( 'a' => '1' ) ) );

		$this->assertSame( 'a=1', $this->django_calls()[0]['body'] );
	}
}
