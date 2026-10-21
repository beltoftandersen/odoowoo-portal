<?php
/**
 * Sync triggers, task status, remembered tasks and logs.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Api\Map;
use OdooWooPortal\Context;
use OdooWooPortal\Tasks;

/**
 * Methods, paths and bodies of the signed calls; what is remembered; how task replies are shown.
 */
final class SyncRoutesTest extends RestTestCase {

	private const D = 7;

	/**
	 * Route under the namespace.
	 *
	 * @param array<string, mixed> $l     License.
	 * @param string               $route Route.
	 * @param int                  $d     Dashboard.
	 */
	private function url( array $l, string $route, int $d = self::D ): string {
		return '/licenses/' . $l['id'] . '/dashboards/' . $d . '/' . $route;
	}

	/**
	 * A user with a ready license.
	 *
	 * @return array<string, mixed>
	 */
	private function owner(): array {
		$l = $this->odoowoo_license();
		$this->as_user( (int) $l['user_id'] );

		return $l;
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function triggers(): array {
		return array(
			'products'   => array( 'trigger-sync', 'products', 'dashboards/7/trigger-sync/' ),
			'categories' => array( 'trigger-category-sync', 'categories', 'dashboards/7/trigger-category-sync/' ),
			'brands'     => array( 'trigger-brand-sync', 'brands', 'dashboards/7/trigger-brand-sync/' ),
			'stock'      => array( 'sync-stock', 'stock', 'dashboards/7/sync-stock/' ),
			'prices'     => array( 'sync-prices', 'prices', 'dashboards/7/sync-prices/' ),
			'barcodes'   => array( 'sync-barcodes', 'barcodes', 'dashboards/7/sync-barcodes/' ),
			'orders'     => array( 'sync-orders', 'orders', 'dashboards/7/sync-orders/' ),
		);
	}

	/**
	 * @dataProvider triggers
	 *
	 * @param string $key  Route.
	 * @param string $kind Kind.
	 * @param string $path Django path.
	 */
	public function test_each_trigger_posts_one_signed_call_and_remembers_the_task( string $key, string $kind, string $path ): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'message' => 'started', 'task_id' => 'tk-1' ) ) );

		$res  = $this->rest( 'POST', $this->url( $l, $key ), 'sync-orders' === $key ? array( 'order_ids' => array( 5 ) ) : array() );
		$call = $this->django_calls();

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 'tk-1', $res->get_data()['data']['task_id'] );
		$this->assertSame( $kind, $res->get_data()['data']['kind'] );
		$this->assertCount( 1, $call );
		$this->assertSame( 'POST', $call[0]['method'] );
		$this->assertSame( '/api/' . $path, $call[0]['path_and_query'] );
		$this->assert_signed( $call[0], $this->tenant( $l ) );
		$this->assertSame( 'tk-1', Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D )['task_id'] );
		$this->assertSame( $kind, Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D )['kind'] );
	}

	public function test_sync_routes_send_ids_and_dates_exactly(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk' ) ) );

		$this->rest( 'POST', $this->url( $l, 'trigger-sync' ), array( 'product_ids' => '1, 2,,x 3', 'consumer_key' => 'nope', 'dashboard_id' => 99 ) );
		$this->rest( 'POST', $this->url( $l, 'sync-stock' ), array( 'product_ids' => array( 3, 3, '4', -1, 0, 'a' ) ) );
		$this->rest( 'POST', $this->url( $l, 'sync-prices' ), array() );
		$this->rest( 'POST', $this->url( $l, 'sync-barcodes' ), array( 'product_ids' => array( 9 ), 'order_ids' => array( 1 ) ) );
		$this->rest( 'POST', $this->url( $l, 'sync-orders' ), array( 'order_ids' => '10 11', 'from_date' => '2026-01-01', 'to_date' => '2026-01-02' ) );
		$this->rest( 'POST', $this->url( $l, 'sync-orders' ), array( 'from_date' => '2026-01-01', 'to_date' => '2026-01-31', 'product_ids' => array( 1 ) ) );
		$this->rest( 'POST', $this->url( $l, 'trigger-category-sync' ), array( 'product_ids' => array( 1 ) ) );

		$bodies = array_map( fn( $c ) => $c['body'], $this->django_calls() );

		$this->assertSame( array( '{"product_ids":[1,2,3]}', '{"product_ids":[3,4]}', '{}', '{"product_ids":[9]}', '{"order_ids":[10,11]}', '{"from_date":"2026-01-01","to_date":"2026-01-31"}', '{}' ), $bodies );
	}

	public function test_id_lists_are_cleaned_and_capped(): void {
		$this->assertSame( array( 1, 2, 3 ), Map::clean_ids( '1, 2,,x 3' ) );
		$this->assertSame( array( 3, 4 ), Map::clean_ids( array( 3, 3, '4', -1, 0, 'a' ) ) );
		$this->assertSame( array(), Map::clean_ids( null ) );
		$this->assertSame( array(), Map::clean_ids( array( 'a' => array( 1 ) ) ) );
		$this->assertCount( 500, Map::clean_ids( range( 1, 500 ) ) );

		try {
			Map::clean_ids( range( 1, 501 ) );
			$this->fail( 'More than the cap must be refused.' );
		} catch ( \LengthException $e ) {
			$this->assertSame( 'At most 500 ids at a time.', $e->getMessage() );
		}

		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk' ) ) );

		$res = $this->rest( 'POST', $this->url( $l, 'trigger-sync' ), array( 'product_ids' => range( 1, 10000 ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_value', $res->get_data()['code'] );
		$this->assertSame( 'At most 500 ids at a time.', $res->get_data()['message'] );
		$this->assertSame( 'product_ids', $res->get_data()['field'] );

		$res = $this->rest( 'POST', $this->url( $l, 'sync-orders' ), array( 'order_ids' => implode( ',', range( 1, 501 ) ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'order_ids', $res->get_data()['field'] );
		$this->assertSame( array(), $this->django_calls(), 'No Django call.' );
		$this->assertNull( Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D ) );
	}

	public function test_ids_that_clean_to_nothing_are_refused(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk' ) ) );

		$res = $this->rest( 'POST', $this->url( $l, 'trigger-sync' ), array( 'product_ids' => 'x, -1' ) );

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_value', $res->get_data()['code'] );
		$this->assertSame( 'Enter at least one valid id, or leave the field empty.', $res->get_data()['message'] );
		$this->assertSame( array(), $this->django_calls() );

		// An empty field is "everything": allowed.
		$res = $this->rest( 'POST', $this->url( $l, 'trigger-sync' ), array( 'product_ids' => '' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '{}', $this->django_calls()[0]['body'] );
	}

	public function test_clean_range(): void {
		$this->assertSame( array( 'from_date' => '2026-01-01', 'to_date' => '2026-01-31' ), Map::clean_range( '2026-01-01', '2026-01-31' ) );
		$this->assertSame( array( 'from_date' => '2026-01-05', 'to_date' => '2026-01-05' ), Map::clean_range( '2026-01-05', '2026-01-05' ) );
		$this->assertNull( Map::clean_range( '', null ) );

		foreach ( array( array( '2026-02-01', '2026-01-01' ), array( 'x', '2026-01-01' ), array( '2026-02-30', '2026-03-01' ), array( '2026-01-01', '' ), array( '', '2026-01-01' ), array( array( '2026-01-01' ), '2026-01-02' ) ) as $i => $pair ) {
			try {
				Map::clean_range( $pair[0], $pair[1] );
				$this->fail( 'Refused: ' . $i );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertContains( $e->getMessage(), array( 'The start date is after the end date.', 'Enter a valid start and end date.' ), (string) $i );
			}
		}

		try {
			Map::clean_range( '2026-02-01', '2026-01-01' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'The start date is after the end date.', $e->getMessage() );
		}
	}

	public function test_range_and_order_input_refusals_at_the_route(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk' ) ) );

		$res = $this->rest( 'POST', $this->url( $l, 'sync-orders' ), array( 'from_date' => '2026-02-01', 'to_date' => '2026-01-01' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'The start date is after the end date.', $res->get_data()['message'] );

		$res = $this->rest( 'POST', $this->url( $l, 'sync-orders' ), array( 'from_date' => 'tomorrow', 'to_date' => '2026-01-01' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_value', $res->get_data()['code'] );

		// Neither ids nor dates: Django would fail the task, so it is refused up front.
		$res = $this->rest( 'POST', $this->url( $l, 'sync-orders' ), array() );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'Enter order ids, or choose a start and end date.', $res->get_data()['message'] );

		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_remember_only_after_202_with_task_id(): void {
		$l = $this->owner();
		$u = (int) $l['user_id'];

		$cases = array(
			'paused'  => array( 409, array( 'code' => 'tenant_paused', 'message' => 'Your account is paused.' ), 409 ),
			'plan'    => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ), 409 ),
			'no id'   => array( 202, array( 'message' => 'started' ), 502 ),
			'200'     => array( 200, array( 'task_id' => 'tk' ), 502 ),
			'garbage' => array( 500, array( 'error' => 'boom' ), 502 ),
			'bad id'  => array( 202, array( 'task_id' => 'a/../b' ), 502 ),
		);

		foreach ( $cases as $name => $case ) {
			$this->fake_django( fn() => array( $case[0], $case[1] ) );
			$res = $this->rest( 'POST', $this->url( $l, 'trigger-brand-sync' ) );

			$this->assertSame( $case[2], $res->get_status(), $name );
			$this->assertNull( Tasks::active( $u, (int) $l['id'], self::D ), $name );
		}

		$this->fake_django( fn() => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ) ) );
		$res = $this->rest( 'POST', $this->url( $l, 'trigger-brand-sync' ) );
		$this->assertSame( 'Brand sync is not included in your plan.', $res->get_data()['message'] );
	}

	public function test_brand_sync_without_plan_shows_django_message(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 403, array( 'code' => 'plan_feature_unavailable', 'message' => 'Brand sync is not included in your plan.', 'field' => 'enable_brand_sync' ) ) );

		$res  = $this->rest( 'POST', $this->url( $l, 'trigger-brand-sync' ) );
		$data = $res->get_data();

		$this->assertSame( 409, $res->get_status(), 'Django 403 never reaches the browser as 403.' );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'plan_feature_unavailable', $data['code'] );
		$this->assertSame( 'Brand sync is not included in your plan.', $data['message'] );
		$this->assertSame( 'enable_brand_sync', $data['field'] );
	}

	public function test_wrong_methods_are_405_before_the_rate_limit_and_no_call(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk' ) ) );

		foreach ( array( 'GET', 'PUT', 'DELETE' ) as $m ) {
			$this->assertSame( 405, $this->rest( $m, $this->url( $l, 'trigger-sync' ) )->get_status(), $m );
		}

		$this->assertSame( 405, $this->rest( 'POST', $this->url( $l, 'task-status/abc' ) )->get_status() );
		$this->assertSame( 405, $this->rest( 'POST', $this->url( $l, 'order-syncs' ) )->get_status() );
		$this->assertSame( array(), $this->django_calls() );
		$this->assertSame( 400, $this->rest( 'POST', $this->url( $l, 'trigger-sync/abc' ) )->get_status(), 'A suffix on a row without a task path.' );
	}

	public function test_sync_bucket_is_limited_per_user(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk' ) ) );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 200, $this->rest( 'POST', $this->url( $l, 'sync-stock' ) )->get_status(), (string) $i );
		}

		$res = $this->rest( 'POST', $this->url( $l, 'sync-prices' ) );
		$this->assertSame( 429, $res->get_status(), 'One bucket for every sync.' );
		$this->assertCount( 10, $this->django_calls() );
	}

	public function test_support_and_read_only_cannot_start_syncs(): void {
		$l = $this->odoowoo_license( array(), 'paused' );
		$this->as_user( (int) $l['user_id'] );
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk' ) ) );

		$res = $this->rest( 'POST', $this->url( $l, 'sync-stock' ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'odoowoo_portal_read_only', $res->get_data()['code'] );
		$this->assertSame( array(), $this->django_calls() );
		$this->assertNull( Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D ) );
	}

	/**
	 * A Django task reply.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function task( array $over = array() ): array {
		return array_merge(
			array(
				'task_id'  => 'tk-1',
				'status'   => 'PROGRESS',
				'state'    => 'running',
				'message'  => 'Task is progress.',
				'results'  => array(),
				'progress' => array( 'current' => 0, 'total' => 0 ),
				'current'  => 0,
				'total'    => 0,
			),
			$over
		);
	}

	/**
	 * Reads a task through the route, after remembering it.
	 *
	 * @param array<string, mixed> $l     License.
	 * @param array<string, mixed> $reply Django reply.
	 * @param int                  $code  Status.
	 */
	private function status( array $l, array $reply, int $code = 200 ): \WP_REST_Response {
		Tasks::remember( (int) $l['user_id'], (int) $l['id'], self::D, 'products', 'tk-1' );
		$this->fake_django( fn() => array( $code, $reply ) );

		return $this->rest( 'GET', $this->url( $l, 'task-status/tk-1' ) );
	}

	public function test_odoowoo_mistake_progress_reads_top_level_current_total(): void {
		$l = $this->owner();

		$res = $this->status( $l, $this->task( array( 'current' => 30, 'total' => 120, 'progress' => array( 'current' => 0, 'total' => 0 ) ) ) );
		$d   = $res->get_data()['data'];

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 30, $d['current'] );
		$this->assertSame( 120, $d['total'] );
		$this->assertSame( 'running', $d['state'] );
		$this->assertSame( 'Running', $d['label'] );
		$this->assertSame( 'tk-1', $d['task_id'] );
		$this->assertSame( 'GET', $this->django_calls()[0]['method'] );
		$this->assertSame( '/api/dashboards/7/task-status/tk-1/', $this->django_calls()[0]['path_and_query'] );

		// The old shape has only progress.
		$old = $this->task( array( 'progress' => array( 'current' => 4, 'total' => 9 ) ) );
		unset( $old['current'], $old['total'] );
		$d = $this->status( $l, $old )->get_data()['data'];
		$this->assertSame( array( 4, 9, 'running' ), array( $d['current'], $d['total'], $d['state'] ) );

		// Neither: 0/0, still running.
		$none = $this->task();
		unset( $none['current'], $none['total'], $none['progress'] );
		$d = $this->status( $l, $none )->get_data()['data'];
		$this->assertSame( array( 0, 0, 'running' ), array( $d['current'], $d['total'], $d['state'] ) );

		// Top-level zero beats a non-zero progress: it is the real number.
		$d = $this->status( $l, $this->task( array( 'current' => 0, 'total' => 5, 'progress' => array( 'current' => 3, 'total' => 8 ) ) ) )->get_data()['data'];
		$this->assertSame( array( 0, 5 ), array( $d['current'], $d['total'] ) );
	}

	public function test_odoowoo_mistake_skipped_and_failed_not_completed(): void {
		$l = $this->owner();

		$d = $this->status( $l, $this->task( array( 'status' => 'SKIPPED', 'state' => 'skipped', 'message' => 'Sync categories is deactivated.' ) ) )->get_data()['data'];
		$this->assertSame( array( 'skipped', 'Skipped', 'Sync categories is deactivated.' ), array( $d['state'], $d['label'], $d['message'] ) );

		$d = $this->status( $l, $this->task( array( 'status' => 'FAILED', 'state' => 'failed', 'message' => 'The sync failed. See the sync log for details.' ) ) )->get_data()['data'];
		$this->assertSame( array( 'failed', 'Failed', 'The sync failed. See the sync log for details.' ), array( $d['state'], $d['label'], $d['message'] ) );

		// Celery's SUCCESS status alone never means Completed.
		$d = $this->status( $l, $this->task( array( 'status' => 'SUCCESS', 'state' => 'skipped' ) ) )->get_data()['data'];
		$this->assertSame( 'Skipped', $d['label'] );

		$d = $this->status( $l, $this->task( array( 'status' => 'SUCCESS', 'state' => 'done', 'message' => 'Done.' ) ) )->get_data()['data'];
		$this->assertSame( array( 'done', 'Completed' ), array( $d['state'], $d['label'] ) );

		// A reply with no state, or an unknown one, is failed, with the fixed message.
		foreach ( array( 'unknown', 'SUCCESS', '', null, 5 ) as $odd ) {
			$d = $this->status( $l, $this->task( array( 'status' => 'SUCCESS', 'state' => $odd, 'message' => 'Traceback: secret.py line 3' ) ) )->get_data()['data'];
			$this->assertSame( array( 'failed', 'Failed', 'The sync failed. See the sync log for details.' ), array( $d['state'], $d['label'], $d['message'] ), var_export( $odd, true ) );
		}

		$no_state = $this->task( array( 'status' => 'SUCCESS' ) );
		unset( $no_state['state'] );
		$this->assertSame( 'failed', $this->status( $l, $no_state )->get_data()['data']['state'] );
	}

	public function test_failed_items_show_a_fixed_message_never_exception_text(): void {
		$l = $this->owner();

		$results = array(
			array( 'order' => 'SO1', 'status' => 'success', 'extra' => 'dropped' ),
			array( 'order' => 'SO2', 'status' => 'error', 'error' => 'psycopg2 password=hunter2', 'message' => 'Traceback boom', 'code' => 'sync_item_failed' ),
			array( 'product_name' => 'Tea', 'sku' => 'T1', 'product_id' => 9, 'status' => 'skipped', 'reason' => 'No SKU' ),
			'not an entry',
		);
		$d       = $this->status( $l, $this->task( array( 'state' => 'done', 'results' => $results ) ) )->get_data()['data'];

		$this->assertSame(
			array(
				array( 'order' => 'SO1', 'status' => 'success' ),
				array( 'order' => 'SO2', 'status' => 'error', 'message' => 'This item was not synced. See the sync log for details.' ),
				array( 'product_name' => 'Tea', 'sku' => 'T1', 'product_id' => 9, 'status' => 'skipped', 'reason' => 'No SKU' ),
			),
			$d['results']
		);
		$this->assertStringNotContainsString( 'hunter2', (string) wp_json_encode( $d ) );

		$d = $this->status( $l, $this->task( array( 'state' => 'done', 'results' => 'x' ) ) )->get_data()['data'];
		$this->assertSame( array(), $d['results'] );
	}

	public function test_odoowoo_mistake_running_task_resumed_after_reload(): void {
		$l = $this->owner();
		$u = (int) $l['user_id'];
		$this->fake_django( fn() => array( 202, array( 'task_id' => 'tk-9', 'message' => 'started' ) ) );
		$this->rest( 'POST', $this->url( $l, 'sync-stock' ) );

		$this->fake_django( fn() => array( 500, 'must not be called' ) );
		$res = $this->rest( 'GET', $this->url( $l, 'sync/active' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'tk-9', $res->get_data()['data']['task']['task_id'] );
		$this->assertSame( 'stock', $res->get_data()['data']['task']['kind'] );
		$this->assertIsInt( $res->get_data()['data']['task']['started'] );
		$this->assertSame( array(), $this->django_calls(), 'sync/active is answered locally.' );

		// Still running: stays.
		$this->fake_django( fn() => array( 200, $this->task( array( 'task_id' => 'tk-9', 'current' => 1, 'total' => 2 ) ) ) );
		$this->rest( 'GET', $this->url( $l, 'task-status/tk-9' ) );
		$this->assertSame( 'tk-9', $this->rest( 'GET', $this->url( $l, 'sync/active' ) )->get_data()['data']['task']['task_id'] );

		// Done: forgotten.
		$this->fake_django( fn() => array( 200, $this->task( array( 'task_id' => 'tk-9', 'state' => 'done', 'status' => 'SUCCESS' ) ) ) );
		$this->rest( 'GET', $this->url( $l, 'task-status/tk-9' ) );
		$res = $this->rest( 'GET', $this->url( $l, 'sync/active' ) );

		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertNull( $res->get_data()['data']['task'] );
		$this->assertFalse( Tasks::owns( $u, (int) $l['id'], self::D, 'tk-9' ) );
	}

	public function test_failed_and_skipped_and_unknown_task_states_forget_the_task(): void {
		$l = $this->owner();

		foreach ( array( 'failed', 'skipped', 'weird' ) as $state ) {
			$this->status( $l, $this->task( array( 'state' => $state ) ) );
			$this->assertNull( Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D ), $state );
		}
	}

	public function test_a_django_task_not_found_forgets_the_task_and_is_404(): void {
		$l   = $this->owner();
		$res = $this->status( $l, array( 'code' => 'task_not_found', 'message' => 'This sync was not found. It may have finished more than a day ago.' ), 404 );

		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'task_not_found', $res->get_data()['code'] );
		$this->assertNull( Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D ) );
	}

	public function test_a_failed_status_read_keeps_the_task_and_shows_the_generic_message(): void {
		$l   = $this->owner();
		$res = $this->status( $l, array( 'code' => 'task_status_unavailable', 'message' => 'The sync status could not be read. Try again in a moment.' ), 500 );

		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'tk-1', Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D )['task_id'], 'Not known to be over.' );

		$res = $this->status( $l, array( 'error' => 'boom' ), 500 );
		$this->assertSame( 502, $res->get_status() );
		$this->assertFalse( $res->get_data()['ok'] );
		$this->assertStringNotContainsString( 'boom', (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'tk-1', Tasks::active( (int) $l['user_id'], (int) $l['id'], self::D )['task_id'] );

		$this->fake_django( fn() => array( 200, 'plain text' ) );
		$res = $this->rest( 'GET', $this->url( $l, 'task-status/tk-1' ) );
		$this->assertFalse( $res->get_data()['ok'] );
	}

	public function test_task_status_of_unknown_task_is_404_and_makes_no_django_call(): void {
		$l  = $this->owner();
		$u  = (int) $l['user_id'];
		$l2 = $this->odoowoo_license();
		$this->fake_django( fn() => array( 200, $this->task() ) );

		Tasks::remember( $u, (int) $l['id'], 8, 'products', 'on-other-dashboard' );
		Tasks::remember( (int) $l2['user_id'], (int) $l2['id'], self::D, 'products', 'of-another-user' );
		Tasks::remember( $u, 99999, self::D, 'products', 'on-other-license' );

		foreach ( array( 'on-other-dashboard', 'of-another-user', 'on-other-license', 'made-up' ) as $task ) {
			$res = $this->rest( 'GET', $this->url( $l, 'task-status/' . $task ) );

			$this->assertSame( 404, $res->get_status(), $task );
			$this->assertSame( 'odoowoo_portal_task_unknown', $res->get_data()['code'], $task );
			$this->assertFalse( $res->get_data()['ok'] );
		}

		$res = $this->rest( 'GET', $this->url( $l, 'task-status' ) );
		$this->assertSame( 404, $res->get_status(), 'No task at all.' );
		$this->assertSame( 'odoowoo_portal_task_unknown', $res->get_data()['code'] );
		$this->assertSame( array(), $this->django_calls() );

		// The same ids are fine for the right dashboard.
		$this->assertSame( 200, $this->rest( 'GET', $this->url( $l, 'task-status/on-other-dashboard', 8 ) )->get_status() );
		$this->assertCount( 1, $this->django_calls() );
	}

	public function test_task_status_of_expired_remembered_task_is_unknown(): void {
		$l = $this->owner();
		update_user_meta( (int) $l['user_id'], 'odoowoo_portal_tasks', array( array( 'license' => (int) $l['id'], 'dashboard' => self::D, 'kind' => 'products', 'task_id' => 'old', 'started' => time() - DAY_IN_SECONDS - 10 ) ) );
		$this->fake_django( fn() => array( 200, $this->task() ) );

		$this->assertSame( 404, $this->rest( 'GET', $this->url( $l, 'task-status/old' ) )->get_status() );
		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_task_status_needs_a_login_and_a_license(): void {
		$l = $this->owner();
		Tasks::remember( (int) $l['user_id'], (int) $l['id'], self::D, 'products', 'tk-1' );
		$this->fake_django( fn() => array( 200, $this->task() ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', $this->url( $l, 'task-status/tk-1' ) )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', $this->url( $l, 'sync/active' ) )->get_status() );

		$this->as_user( self::factory()->user->create() );
		Context::reset();
		$this->assertSame( 403, $this->rest( 'GET', $this->url( $l, 'task-status/tk-1' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', $this->url( $l, 'sync/active' ) )->get_status() );
		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_sync_active_is_not_routed_to_django(): void {
		$l = $this->owner();
		$this->fake_django( fn() => array( 500, 'must not be called' ) );

		$res = $this->rest( 'GET', $this->url( $l, 'sync/active' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'ok' => true, 'data' => array( 'task' => null ) ), $res->get_data() );
		$this->assertArrayNotHasKey( 'sync', Map::ROUTES );
		$this->assertSame( 405, $this->rest( 'POST', $this->url( $l, 'sync/active' ) )->get_status() );
		$this->assertSame( array(), $this->django_calls() );
	}

	public function test_sync_active_is_per_dashboard_and_license(): void {
		$l = $this->owner();
		Tasks::remember( (int) $l['user_id'], (int) $l['id'], 8, 'orders', 'other' );
		$this->fake_django( fn() => array( 500, 'x' ) );

		$this->assertNull( $this->rest( 'GET', $this->url( $l, 'sync/active' ) )->get_data()['data']['task'] );
		$this->assertSame( 'other', $this->rest( 'GET', $this->url( $l, 'sync/active', 8 ) )->get_data()['data']['task']['task_id'] );
	}

	public function test_logs_route_returns_newest_first_with_status_text(): void {
		$l    = $this->owner();
		$rows = array(
			array( 'woocommerce_order_id' => 'A-30', 'odoo_order_id' => 'S0030', 'status' => 'success', 'synced_at' => '2026-10-21T10:00:00Z', 'log' => 'Order synced.' ),
			array( 'woocommerce_order_id' => 'A-29', 'odoo_order_id' => null, 'status' => 'failed', 'synced_at' => '2026-10-20T10:00:00Z', 'log' => 'No stock.' ),
			array( 'woocommerce_order_id' => 'A-28', 'odoo_order_id' => null, 'status' => 'warning', 'synced_at' => '2026-10-19T10:00:00Z', 'log' => null ),
			array( 'woocommerce_order_id' => 'A-27', 'odoo_order_id' => null, 'status' => 'error', 'synced_at' => '2026-10-18T10:00:00Z', 'log' => '' ),
			array( 'woocommerce_order_id' => 'A-26', 'odoo_order_id' => null, 'status' => 'mystery', 'synced_at' => '2026-10-17T10:00:00Z', 'log' => 'x' ),
		);
		$this->fake_django( fn() => array( 200, $rows ) );

		$res  = $this->rest( 'GET', $this->url( $l, 'order-syncs' ) );
		$call = $this->django_calls()[0];
		$data = $res->get_data()['data']['items'];

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '/api/dashboards/7/order-syncs/', $call['path_and_query'] );
		$this->assertSame( array( 'A-30', 'A-29', 'A-28', 'A-27', 'A-26' ), array_column( $data, 'woocommerce_order_id' ), 'Django order, not re-sorted.' );
		$this->assertSame( array( 'Synced', 'Failed', 'Warning', 'Failed', 'Unknown' ), array_column( $data, 'status_text' ) );
		$this->assertSame( array( 'Order synced.', 'No stock.', '', '', 'x' ), array_column( $data, 'log' ) );
		$this->assertSame( 'success', $data[0]['status'] );
		$this->assertSame( 'S0030', $data[0]['odoo_order_id'] );
		$this->assertSame( '', $data[1]['odoo_order_id'] );

		$this->fake_django( fn() => array( 200, array() ) );
		$this->assertSame( array(), $this->rest( 'GET', $this->url( $l, 'order-syncs' ) )->get_data()['data']['items'] );
	}

	public function test_dashboard_delete_forgets_its_tasks(): void {
		$l = $this->owner();
		$u = (int) $l['user_id'];
		Tasks::register();
		Tasks::remember( $u, (int) $l['id'], 5, 'orders', 'gone' );
		Tasks::remember( $u, (int) $l['id'], 6, 'orders', 'stays' );
		$this->fake_django( fn() => array( 204, '' ) );

		$this->rest( 'DELETE', '/licenses/' . $l['id'] . '/dashboards/5' );

		$this->assertFalse( Tasks::owns( $u, (int) $l['id'], 5, 'gone' ) );
		$this->assertTrue( Tasks::owns( $u, (int) $l['id'], 6, 'stays' ) );
	}

	public function test_woo_settings_reply_carries_used_prefixes_of_other_dashboards(): void {
		$l = $this->owner();
		$t = $this->tenant( $l );
		$this->django_map(
			array(
				'GET dashboards/'                  => array( 200, array( array( 'id' => 7, 'name' => 'A' ), array( 'id' => 8, 'name' => 'B' ) ) ),
				'GET dashboards/7/woo-keys/'       => array( 200, array( 'prefix' => 'AA', 'consumer_key' => 'ck', 'used_prefixes' => array( 'AA', 'BB', 'ZZ' ) ) ),
				'GET dashboards/8/woo-keys/'       => array( 200, array( 'prefix' => 'BB', 'used_prefixes' => array( 'AA', 'BB' ) ) ),
			)
		);

		$res = $this->rest( 'GET', $this->url( $l, 'woo-keys' ) );
		$d   = $res->get_data()['data'];

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'AA', $d['prefix'] );
		$this->assertSame( array( 'BB' ), $d['used_prefixes'], 'Own prefix excluded; Django\'s own list is replaced.' );
		$this->assertNotEmpty( $t );
	}

	public function test_woo_settings_read_survives_a_failed_prefix_read(): void {
		$l = $this->owner();
		$this->django_map( array( 'GET dashboards/7/woo-keys/' => array( 200, array( 'prefix' => 'AA' ) ) ) );

		$d = $this->rest( 'GET', $this->url( $l, 'woo-keys' ) )->get_data()['data'];

		$this->assertSame( 'AA', $d['prefix'] );
		$this->assertSame( array(), $d['used_prefixes'] );
	}
}
