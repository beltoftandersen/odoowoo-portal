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

	public function set_up(): void {
		parent::set_up();

		$this->django = null;

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Connections::OPTION );
		delete_option( Settings::OPTION );
	}

	public function tear_down(): void {
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
	 * @return array<int, array{method: string, path_and_query: string, headers: array<string, string>, body: string}>
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
	 * Asserts a recorded call carries the tenant and a correct signature.
	 *
	 * @param array<string, mixed> $call   Recorded call.
	 * @param string               $tenant Expected tenant (resource uuid).
	 */
	protected function assert_signed( array $call, string $tenant ): void {
		$headers = array_change_key_case( $call['headers'], CASE_LOWER );

		$this->assertSame( $tenant, $headers['x-beltoft-tenant'] ?? null, 'Tenant header.' );
		$this->assertArrayHasKey( 'x-beltoft-timestamp', $headers );

		$expected = Signer::signature( self::SECRET, $call['method'], $call['path_and_query'], $headers['x-beltoft-timestamp'], $tenant, $call['body'] );

		$this->assertSame( $expected, $headers['x-beltoft-signature'] ?? null, 'Signature.' );
	}
}
