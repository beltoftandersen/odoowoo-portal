<?php
/**
 * Shared helpers for the test suite.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftServices\Http\Connections;
use BeltoftServices\Http\Signer;
use BeltoftServices\Resource;
use OdooWooPortal\Settings;
use OdooWooPortal\Tests\Support\FakeDjango;
use OdooWooPortal\Tests\Support\FakeHttpDriver;

/**
 * Base test case: connection, license with a resource, and a fake Django.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * The active fake Django.
	 *
	 * @var FakeDjango|null
	 */
	private $django = null;

	/**
	 * The shared secret used by connection().
	 */
	protected const SECRET = 's3cret-s3cret-s3cret';

	/**
	 * Outbound requests nothing in the test answered.
	 *
	 * @var string[]
	 */
	private $unexpected_http = array();

	public function set_up(): void {
		parent::set_up();

		$this->django          = null;
		$this->unexpected_http = array();

		// Last word: whatever no fake answered never leaves the test.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 9999, 3 );

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Connections::OPTION );
		delete_option( Settings::OPTION );
	}

	/**
	 * Refuses a request that no fake answered.
	 *
	 * @param mixed                $preempt Value so far.
	 * @param array<string, mixed> $args    Arguments.
	 * @param string               $url     URL.
	 * @return mixed
	 */
	public function block_http( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		$this->unexpected_http[] = $url;

		return new \WP_Error( 'odoowoo_test_blocked', 'Unexpected outbound request in a test: ' . $url );
	}

	/**
	 * For a test that provokes a blocked request on purpose: forget the blocked ones.
	 */
	protected function acknowledge_blocked_http(): void {
		$this->unexpected_http = array();
	}

	public function tear_down(): void {
		$unexpected = $this->unexpected_http;
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 9999 );
		$this->unexpected_http = array();
		$this->assertSame( array(), $unexpected, 'No unexpected outbound HTTP.' );

		remove_all_filters( 'beltoft_services_key' );
		delete_option( Connections::OPTION );
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * Merges settings over the saved ones.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	protected function settings( array $values ): void {
		$current = get_option( Settings::OPTION, array() );
		update_option( Settings::OPTION, array_merge( is_array( $current ) ? $current : array(), $values ) );
	}

	/**
	 * Saves an HMAC connection to the staging Django address and selects it in the settings.
	 *
	 * @param string $id Connection id.
	 */
	protected function connection( string $id = 'odoowoo' ): void {
		$saved = Connections::save(
			$id,
			array(
				'label'    => 'OdooWoo',
				'base_url' => 'http://127.0.0.1:8101',
				'auth'     => 'hmac',
				'secret'   => self::SECRET,
				'timeout'  => '15',
			)
		);

		$this->assertTrue( $saved, 'The connection was saved.' );
		$this->settings( array( 'connection_id' => $id ) );
	}

	/**
	 * A license of the configured software slug for a new user, with a services resource.
	 * $meta overrides the resource meta defaults ('state' is 'ready' unless given); the
	 * resource keeps its own generated uuid.
	 *
	 * @param array<string, mixed> $data  License columns.
	 * @param string               $state Resource state.
	 * @return array<string, mixed> The license row.
	 */
	protected function odoowoo_license( array $data = array(), string $state = 'ready' ): array {
		$data = array_merge(
			array(
				'product_slug'    => (string) Settings::get( 'software_slug' ),
				'email'           => 'customer@example.com',
				'user_id'         => self::factory()->user->create(),
				'max_activations' => 3,
				'source'          => 'order',
			),
			$data
		);

		global $wp_filter;
		$saved = $wp_filter['beltoft_licenses_license_created'] ?? null;
		unset( $wp_filter['beltoft_licenses_license_created'] );

		$license = \BeltoftLicenses\Licenses::create( $data );

		if ( null !== $saved ) {
			$wp_filter['beltoft_licenses_license_created'] = $saved;
		}

		$this->assertIsArray( $license, 'The license was created.' );

		$resource = Resource::create( $license, new FakeHttpDriver(), array(), array( 'state' => $state ) );

		$this->assertInstanceOf( Resource::class, $resource, 'The resource was created.' );

		return $license;
	}

	/**
	 * Installs a fake Django. The responder gets the call and returns [status, array|string].
	 *
	 * @param callable $responder Responder.
	 */
	protected function fake_django( callable $responder ): void {
		if ( $this->django ) {
			remove_filter( 'pre_http_request', array( $this->django, 'handle' ), 10 );
		}

		$this->django = new FakeDjango( $responder );
		add_filter( 'pre_http_request', array( $this->django, 'handle' ), 10, 3 );
	}

	/**
	 * Calls the fake Django received.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function django_calls(): array {
		return $this->django ? $this->django->calls() : array();
	}

	/**
	 * Logs a user in.
	 *
	 * @param int $id User id.
	 */
	protected function as_user( int $id ): void {
		wp_set_current_user( $id );
	}

	/**
	 * Asserts a recorded call carries the tenant and a correct signature, under the header
	 * names of the selected connection. The secret is the one connection() saved.
	 *
	 * @param array<string, mixed> $call   Recorded call.
	 * @param string               $tenant Expected tenant (resource uuid).
	 * @param string               $secret Secret the signature must come from.
	 */
	protected function assert_signed( array $call, string $tenant, string $secret = self::SECRET ): void {
		$connection = Connections::get( (string) Settings::get( 'connection_id' ) ) ?? array();
		$names      = array(
			'tenant'    => strtolower( (string) ( $connection['header_tenant'] ?? 'X-Beltoft-Tenant' ) ),
			'timestamp' => strtolower( (string) ( $connection['header_timestamp'] ?? 'X-Beltoft-Timestamp' ) ),
			'signature' => strtolower( (string) ( $connection['header_signature'] ?? 'X-Beltoft-Signature' ) ),
		);
		$headers    = array_change_key_case( $call['headers'], CASE_LOWER );

		$this->assertSame( $tenant, $headers[ $names['tenant'] ] ?? null, 'Tenant header.' );
		$this->assertArrayHasKey( $names['timestamp'], $headers );

		$expected = Signer::signature( $secret, $call['method'], $call['path_and_query'], $headers[ $names['timestamp'] ], $tenant, $call['body'] );

		$this->assertSame( $expected, $headers[ $names['signature'] ] ?? null, 'Signature.' );
	}
}
