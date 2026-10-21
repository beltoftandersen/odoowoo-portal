<?php
/**
 * Prefixes in use across a customer's dashboards.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Context;
use OdooWooPortal\Prefixes;

/**
 * Reads dashboards and woo-keys of every license, cached and capped.
 */
final class PrefixesTest extends RestTestCase {

	/**
	 * Two licenses of one user: AA (Shop A, id 1) and BB (id 2) in the first/second tenant.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function two(): array {
		$a = $this->odoowoo_license();
		$b = $this->odoowoo_license( array( 'user_id' => (int) $a['user_id'] ) );
		$this->as_user( (int) $a['user_id'] );

		return array( $a, $b );
	}

	/**
	 * Fake Django: tenant uuid => list of dashboard id => prefix.
	 *
	 * @param array<string, array<int, string>> $by_tenant Dashboards.
	 */
	private function serve( array $by_tenant ): void {
		$this->fake_django(
			function ( $call ) use ( $by_tenant ) {
				$tenant = $call['headers']['X-Beltoft-Tenant'] ?? '';
				$path   = preg_replace( '#^/api/#', '', $call['path_and_query'] );

				if ( ! isset( $by_tenant[ $tenant ] ) ) {
					return array( 502, '<html>down</html>' );
				}

				if ( 'dashboards/' === $path ) {
					$rows = array();

					foreach ( $by_tenant[ $tenant ] as $id => $prefix ) {
						$rows[] = array( 'id' => $id, 'name' => 'Shop ' . $prefix );
					}

					return array( 200, $rows );
				}

				if ( preg_match( '#^dashboards/(\d+)/woo-keys/$#', $path, $m ) ) {
					return array( 200, array( 'prefix' => $by_tenant[ $tenant ][ (int) $m[1] ] ?? '', 'used_prefixes' => array( 'AA', 'BB' ) ) );
				}

				return array( 500, 'unexpected' );
			}
		);
	}

	public function test_odoowoo_mistake_prefixes_in_use_marked(): void {
		list( $a, $b ) = $this->two();
		$this->serve(
			array(
				$this->tenant( $a ) => array( 1 => 'AA' ),
				$this->tenant( $b ) => array( 2 => 'BB' ),
			)
		);

		$this->assertSame( array( 'BB' ), Prefixes::in_use( (int) $a['user_id'], 1 ) );
		$this->assertSame( array( 'AA' ), Prefixes::in_use( (int) $a['user_id'], 2 ) );
		$this->assertSame( array( 'AA', 'BB' ), Prefixes::in_use( (int) $a['user_id'], 99 ), 'A new dashboard sees both.' );
	}

	public function test_an_unavailable_license_is_skipped_not_fatal(): void {
		list( $a, $b ) = $this->two();
		$this->serve( array( $this->tenant( $b ) => array( 2 => 'BB' ) ) );

		$this->assertSame( array( 'BB' ), Prefixes::in_use( (int) $a['user_id'], 1 ) );
	}

	public function test_a_license_that_is_not_ready_makes_no_call(): void {
		$a = $this->odoowoo_license( array(), 'provisioning' );
		$this->as_user( (int) $a['user_id'] );
		$this->serve( array() );

		$this->assertSame( array(), Prefixes::in_use( (int) $a['user_id'], 1 ) );
		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_second_call_within_60_seconds_makes_no_django_call(): void {
		list( $a, $b ) = $this->two();
		$this->serve(
			array(
				$this->tenant( $a ) => array( 1 => 'AA' ),
				$this->tenant( $b ) => array( 2 => 'BB' ),
			)
		);

		Prefixes::in_use( (int) $a['user_id'], 1 );
		$first = count( $this->django_calls() );
		$this->assertSame( 4, $first, 'Two lists and two woo-keys reads.' );

		Context::reset();
		$this->assertSame( array( 'BB' ), Prefixes::in_use( (int) $a['user_id'], 1 ) );
		$this->assertCount( $first, $this->django_calls() );

		Prefixes::forget( (int) $a['user_id'] );
		Prefixes::in_use( (int) $a['user_id'], 1 );
		$this->assertCount( $first * 2, $this->django_calls(), 'Forgotten, so read again.' );
	}

	public function test_reads_are_capped_at_20(): void {
		$a    = $this->odoowoo_license();
		$rows = array();

		for ( $i = 1; $i <= 30; $i++ ) {
			$rows[ $i ] = 'P' . $i;
		}

		$this->as_user( (int) $a['user_id'] );
		$this->serve( array( $this->tenant( $a ) => $rows ) );

		$used = Prefixes::in_use( (int) $a['user_id'], 1000 );

		$this->assertCount( 20, $this->django_calls() );
		$this->assertCount( 19, $used, 'One list read and 19 woo-keys reads.' );
	}

	public function test_empty_prefixes_and_duplicates_are_dropped(): void {
		list( $a, $b ) = $this->two();
		$this->serve(
			array(
				$this->tenant( $a ) => array( 1 => '', 3 => 'CC' ),
				$this->tenant( $b ) => array( 2 => 'CC' ),
			)
		);

		$this->assertSame( array( 'CC' ), Prefixes::in_use( (int) $a['user_id'], 9 ) );
	}

	public function test_other_users_license_is_never_read(): void {
		list( $a, $b ) = $this->two();
		$other = $this->odoowoo_license();
		$this->serve(
			array(
				$this->tenant( $a )     => array( 1 => 'AA' ),
				$this->tenant( $b )     => array( 2 => 'BB' ),
				$this->tenant( $other ) => array( 3 => 'ZZ' ),
			)
		);

		$this->assertSame( array( 'AA', 'BB' ), Prefixes::in_use( (int) $a['user_id'], 99 ) );
	}
}
