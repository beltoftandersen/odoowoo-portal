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
}
