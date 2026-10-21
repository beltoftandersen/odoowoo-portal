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
	 * Ids of the licenses these tests create, deleted again afterwards (custom tables are not
	 * covered by the test rollback).
	 *
	 * @var int[]
	 */
	private array $created = array();

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->created as $id ) {
			$wpdb->delete( $wpdb->prefix . 'beltoft_licenses', array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$this->created = array();
		parent::tear_down();
	}

	/**
	 * A license for the test, remembered for clean-up.
	 *
	 * @param array<string, mixed> $data  License data.
	 * @param string               $state Resource state.
	 * @return array<string, mixed>
	 */
	private function license( array $data = array(), string $state = 'ready' ): array {
		global $wpdb;

		if ( ! isset( $data['user_id'] ) ) {
			$data['user_id'] = self::factory()->user->create();
			// User ids are reused after the rollback; licenses of an earlier test with this id are not ours.
			$wpdb->delete( $wpdb->prefix . 'beltoft_licenses', array( 'user_id' => $data['user_id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$l               = $this->odoowoo_license( $data, $state );
		$this->created[] = (int) $l['id'];

		return $l;
	}

	/**
	 * Two licenses of one user: AA (Shop A, id 1) and BB (id 2) in the first/second tenant.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function two(): array {
		$a = $this->license();
		$b = $this->license( array( 'user_id' => (int) $a['user_id'] ) );
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

		$this->assertSame( array( 'BB' ), Prefixes::status( (int) $a['user_id'], 1 )['used'] );
		$this->assertSame( array( 'AA' ), Prefixes::status( (int) $a['user_id'], 2 )['used'] );
		$this->assertSame( array( 'AA', 'BB' ), Prefixes::status( (int) $a['user_id'], 99 )['used'], 'A new dashboard sees both.' );
	}

	public function test_an_unavailable_license_is_skipped_not_fatal(): void {
		list( $a, $b ) = $this->two();
		$this->serve( array( $this->tenant( $b ) => array( 2 => 'BB' ) ) );

		$this->assertSame( array( 'BB' ), Prefixes::status( (int) $a['user_id'], 1 )['used'] );
	}

	public function test_a_license_that_is_not_ready_makes_no_call(): void {
		$a = $this->license( array(), 'provisioning' );
		$this->as_user( (int) $a['user_id'] );
		$this->serve( array() );

		$this->assertSame( array(), Prefixes::status( (int) $a['user_id'], 1 )['used'] );
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

		Prefixes::status( (int) $a['user_id'], 1 )['used'];
		$first = count( $this->django_calls() );
		$this->assertSame( 4, $first, 'Two lists and two woo-keys reads.' );

		Context::reset();
		$this->assertSame( array( 'BB' ), Prefixes::status( (int) $a['user_id'], 1 )['used'] );
		$this->assertCount( $first, $this->django_calls() );

		Prefixes::forget( (int) $a['user_id'] );
		Prefixes::status( (int) $a['user_id'], 1 )['used'];
		$this->assertCount( $first * 2, $this->django_calls(), 'Forgotten, so read again.' );
	}

	public function test_reads_are_capped_at_20(): void {
		$a    = $this->license();
		$rows = array();

		for ( $i = 1; $i <= 30; $i++ ) {
			$rows[ $i ] = 'P' . $i;
		}

		$this->as_user( (int) $a['user_id'] );
		$this->serve( array( $this->tenant( $a ) => $rows ) );

		$used = Prefixes::status( (int) $a['user_id'], 1000 )['used'];

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

		$this->assertSame( array( 'CC' ), Prefixes::status( (int) $a['user_id'], 9 )['used'] );
	}

	public function test_other_users_license_is_never_read(): void {
		list( $a, $b ) = $this->two();
		$other = $this->license();
		$this->serve(
			array(
				$this->tenant( $a )     => array( 1 => 'AA' ),
				$this->tenant( $b )     => array( 2 => 'BB' ),
				$this->tenant( $other ) => array( 3 => 'ZZ' ),
			)
		);

		$this->assertSame( array( 'AA', 'BB' ), Prefixes::status( (int) $a['user_id'], 99 )['used'] );
	}

	public function test_a_failed_read_is_not_cached(): void {
		list( $a, $b ) = $this->two();
		$up = false;
		$this->fake_django(
			function ( $call ) use ( &$up, $a, $b ) {
				$tenant = $call['headers']['X-Beltoft-Tenant'] ?? '';
				$path   = preg_replace( '#^/api/#', '', $call['path_and_query'] );

				if ( $tenant === $this->tenant( $b ) && ! $up ) {
					return array( 502, 'down' );
				}

				if ( 'dashboards/' === $path ) {
					return array( 200, array( array( 'id' => $tenant === $this->tenant( $a ) ? 1 : 2 ) ) );
				}

				return array( 200, array( 'prefix' => $tenant === $this->tenant( $a ) ? 'AA' : 'BB' ) );
			}
		);

		$this->assertSame( array( 'AA' ), Prefixes::status( (int) $a['user_id'], 99 )['used'] );

		$up = true;
		$this->assertSame( array( 'AA', 'BB' ), Prefixes::status( (int) $a['user_id'], 99 )['used'], 'The failure was not remembered.' );

		$before = count( $this->django_calls() );
		Prefixes::status( (int) $a['user_id'], 99 )['used'];
		$this->assertCount( $before, $this->django_calls(), 'A complete result is cached.' );
	}

	public function test_status_says_partial_for_a_cap_a_failed_read_and_never_for_a_complete_or_cached_one(): void {
		// The cap: 30 dashboards, 20 reads.
		$a    = $this->license();
		$rows = array();

		for ( $i = 1; $i <= 30; $i++ ) {
			$rows[ $i ] = 'P' . $i;
		}

		$this->as_user( (int) $a['user_id'] );
		$this->serve( array( $this->tenant( $a ) => $rows ) );
		$status = Prefixes::status( (int) $a['user_id'], 1000 );
		$this->assertTrue( $status['partial'], 'The cap leaves dashboards unread.' );
		$this->assertCount( 19, $status['used'] );

		// The cap hit between licenses (second license never read).
		Prefixes::forget( (int) $a['user_id'] );
		$b = $this->license( array( 'user_id' => (int) $a['user_id'] ) );
		Context::reset();
		$this->serve(
			array(
				$this->tenant( $a ) => array_slice( $rows, 0, 19, true ),
				$this->tenant( $b ) => array( 99 => 'QQ' ),
			)
		);
		$this->assertTrue( Prefixes::status( (int) $a['user_id'], 1000 )['partial'] );

		// Complete, then cached: not partial.
		Prefixes::forget( (int) $a['user_id'] );
		$this->serve(
			array(
				$this->tenant( $a ) => array( 1 => 'AA' ),
				$this->tenant( $b ) => array( 2 => 'BB' ),
			)
		);
		$this->assertFalse( Prefixes::status( (int) $a['user_id'], 1 )['partial'] );
		$this->assertFalse( Prefixes::status( (int) $a['user_id'], 1 )['partial'], 'Cached results are complete ones.' );

		// A failed read.
		Prefixes::forget( (int) $a['user_id'] );
		$this->serve( array( $this->tenant( $b ) => array( 2 => 'BB' ) ) );
		$this->assertTrue( Prefixes::status( (int) $a['user_id'], 1 )['partial'] );
	}
}
