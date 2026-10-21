<?php
/**
 * Remembered sync tasks.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Tasks;

/**
 * Storage rules: expiry, cap, scope per user, license and dashboard.
 */
final class TasksTest extends TestCase {

	public function test_remember_and_read_back(): void {
		Tasks::remember( 5, 11, 7, 'products', 'abc-1' );

		$active = Tasks::active( 5, 11, 7 );

		$this->assertSame( 'abc-1', $active['task_id'] );
		$this->assertSame( 'products', $active['kind'] );
		$this->assertIsInt( $active['started'] );
		$this->assertTrue( Tasks::owns( 5, 11, 7, 'abc-1' ) );
		$this->assertFalse( Tasks::owns( 5, 11, 8, 'abc-1' ), 'Another dashboard.' );
		$this->assertFalse( Tasks::owns( 5, 12, 7, 'abc-1' ), 'Another license.' );
		$this->assertFalse( Tasks::owns( 6, 11, 7, 'abc-1' ), 'Another user.' );
		$this->assertFalse( Tasks::owns( 5, 11, 7, 'other' ), 'Another task.' );
		$this->assertNull( Tasks::active( 5, 11, 8 ) );
		$this->assertNull( Tasks::active( 6, 11, 7 ) );
	}

	public function test_forget_removes_only_that_task(): void {
		Tasks::remember( 5, 11, 7, 'products', 'a' );
		Tasks::remember( 5, 11, 7, 'stock', 'b' );
		Tasks::forget( 5, 11, 7, 'b' );

		$this->assertTrue( Tasks::owns( 5, 11, 7, 'a' ) );
		$this->assertFalse( Tasks::owns( 5, 11, 7, 'b' ) );
		$this->assertSame( 'a', Tasks::active( 5, 11, 7 )['task_id'] );
	}

	public function test_entries_expire_after_24h_and_cap_at_20(): void {
		update_user_meta(
			5,
			'odoowoo_portal_tasks',
			array(
				array( 'license' => 11, 'dashboard' => 7, 'kind' => 'products', 'task_id' => 'old', 'started' => time() - DAY_IN_SECONDS - 5 ),
				array( 'license' => 11, 'dashboard' => 7, 'kind' => 'stock', 'task_id' => 'fresh', 'started' => time() - DAY_IN_SECONDS + 600 ),
			)
		);

		$this->assertFalse( Tasks::owns( 5, 11, 7, 'old' ) );
		$this->assertTrue( Tasks::owns( 5, 11, 7, 'fresh' ) );
		$this->assertSame( 'fresh', Tasks::active( 5, 11, 7 )['task_id'] );

		update_user_meta( 5, 'odoowoo_portal_tasks', array() );

		for ( $i = 1; $i <= 21; $i++ ) {
			Tasks::remember( 5, 11, 7, 'products', 'task-' . $i );
		}

		$this->assertFalse( Tasks::owns( 5, 11, 7, 'task-1' ), 'The oldest is evicted.' );
		$this->assertTrue( Tasks::owns( 5, 11, 7, 'task-2' ) );
		$this->assertTrue( Tasks::owns( 5, 11, 7, 'task-21' ) );
		$this->assertCount( 20, get_user_meta( 5, 'odoowoo_portal_tasks', true ) );
	}

	public function test_expired_entries_are_dropped_from_storage_on_read(): void {
		update_user_meta( 5, 'odoowoo_portal_tasks', array( array( 'license' => 1, 'dashboard' => 1, 'kind' => 'x', 'task_id' => 'old', 'started' => 1000 ) ) );

		$this->assertNull( Tasks::active( 5, 1, 1 ) );
		$this->assertSame( array(), get_user_meta( 5, 'odoowoo_portal_tasks', true ) ?: array() );
	}

	public function test_garbage_in_meta_is_ignored(): void {
		update_user_meta( 5, 'odoowoo_portal_tasks', 'nonsense' );
		$this->assertNull( Tasks::active( 5, 1, 1 ) );

		update_user_meta( 5, 'odoowoo_portal_tasks', array( 'x', array( 'task_id' => 5 ) ) );
		$this->assertNull( Tasks::active( 5, 1, 1 ) );
	}

	public function test_forget_dashboard_clears_every_user(): void {
		$u1 = self::factory()->user->create();
		$u2 = self::factory()->user->create();
		Tasks::remember( $u1, 11, 7, 'products', 'a' );
		Tasks::remember( $u2, 11, 7, 'products', 'b' );
		Tasks::remember( $u1, 11, 8, 'products', 'c' );
		Tasks::remember( $u1, 12, 7, 'products', 'd' );

		Tasks::forget_dashboard( 11, 7 );

		$this->assertFalse( Tasks::owns( $u1, 11, 7, 'a' ) );
		$this->assertFalse( Tasks::owns( $u2, 11, 7, 'b' ) );
		$this->assertTrue( Tasks::owns( $u1, 11, 8, 'c' ) );
		$this->assertTrue( Tasks::owns( $u1, 12, 7, 'd' ) );
	}

	public function test_dashboard_deleted_action_forgets_the_tasks(): void {
		Tasks::register();
		$u = self::factory()->user->create();
		Tasks::remember( $u, 11, 7, 'products', 'a' );

		do_action( 'odoowoo_portal_dashboard_deleted', 11, 7 );

		$this->assertFalse( Tasks::owns( $u, 11, 7, 'a' ) );
	}

	/**
	 * The named lock of a user's task writes.
	 *
	 * @param int $user_id User.
	 */
	private function lock_name( int $user_id ): string {
		return \BeltoftServices\Locks::name( 'odoowoo-portal-tasks', $user_id );
	}

	public function test_the_named_lock_is_released_after_a_write(): void {
		global $wpdb;

		Tasks::remember( 5, 11, 7, 'products', 'a' );
		Tasks::forget( 5, 11, 7, 'a' );

		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', $this->lock_name( 5 ) ) ) );
	}

	public function test_a_lock_held_by_another_connection_delays_one_second_then_the_write_still_happens(): void {
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$name  = $this->lock_name( 5 );
		$got   = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0];
		$this->assertSame( '1', (string) $got, 'The second connection holds the lock.' );

		$start = microtime( true );
		Tasks::remember( 5, 11, 7, 'products', 'a' );
		$took = microtime( true ) - $start;

		$this->assertGreaterThanOrEqual( 0.9, $took, 'It waited for the lock.' );
		$this->assertLessThan( 3.0, $took );
		$this->assertTrue( Tasks::owns( 5, 11, 7, 'a' ), 'The fallback still saved.' );

		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', $name ) ), 'Still held by the other connection: this request never took it.' );

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();

		$start = microtime( true );
		Tasks::remember( 5, 11, 7, 'stock', 'b' );
		$this->assertLessThan( 0.5, microtime( true ) - $start, 'A free lock costs nothing.' );
		$this->assertTrue( Tasks::owns( 5, 11, 7, 'b' ) );
	}
}
