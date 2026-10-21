<?php
/**
 * Remembered sync tasks.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

defined( 'ABSPATH' ) || exit;

/**
 * The ids of the syncs a user started, so a reload can resume the progress and the status route
 * can refuse an id the user did not start. User meta; entries last 24 hours; at most 20 a user.
 */
final class Tasks {

	public const META = 'odoowoo_portal_tasks';
	public const MAX  = 20;

	/**
	 * Hooks the forgetting of a deleted dashboard's tasks.
	 */
	public static function register(): void {
		add_action( 'odoowoo_portal_dashboard_deleted', array( __CLASS__, 'forget_dashboard' ), 10, 2 );
	}

	/**
	 * Remembers a task Django accepted.
	 *
	 * @param int    $user_id      User.
	 * @param int    $license_id   License.
	 * @param int    $dashboard_id Dashboard.
	 * @param string $kind         Sync kind.
	 * @param string $task_id      Django's task id.
	 */
	public static function remember( int $user_id, int $license_id, int $dashboard_id, string $kind, string $task_id ): void {
		if ( $user_id <= 0 || '' === $task_id ) {
			return;
		}

		$entries = array_values(
			array_filter(
				self::load( $user_id ),
				static fn( $e ) => $e['task_id'] !== $task_id
			)
		);

		$entries[] = array(
			'license'   => $license_id,
			'dashboard' => $dashboard_id,
			'kind'      => $kind,
			'task_id'   => $task_id,
			'started'   => time(),
		);

		self::save( $user_id, array_slice( $entries, -self::MAX ) );
	}

	/**
	 * The newest live task of a dashboard.
	 *
	 * @param int $user_id      User.
	 * @param int $license_id   License.
	 * @param int $dashboard_id Dashboard.
	 * @return array{task_id: string, kind: string, started: int}|null
	 */
	public static function active( int $user_id, int $license_id, int $dashboard_id ): ?array {
		$found = null;

		foreach ( self::load( $user_id ) as $e ) {
			if ( $e['license'] === $license_id && $e['dashboard'] === $dashboard_id && ( null === $found || $e['started'] >= $found['started'] ) ) {
				$found = $e;
			}
		}

		return null === $found ? null : array(
			'task_id' => $found['task_id'],
			'kind'    => $found['kind'],
			'started' => $found['started'],
		);
	}

	/**
	 * Did this user start this task for this dashboard?
	 *
	 * @param int    $user_id      User.
	 * @param int    $license_id   License.
	 * @param int    $dashboard_id Dashboard.
	 * @param string $task_id      Task.
	 */
	public static function owns( int $user_id, int $license_id, int $dashboard_id, string $task_id ): bool {
		if ( '' === $task_id ) {
			return false;
		}

		foreach ( self::load( $user_id ) as $e ) {
			if ( $e['task_id'] === $task_id && $e['license'] === $license_id && $e['dashboard'] === $dashboard_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Forgets one task of a dashboard.
	 *
	 * @param int    $user_id      User.
	 * @param int    $license_id   License.
	 * @param int    $dashboard_id Dashboard.
	 * @param string $task_id      Task.
	 */
	public static function forget( int $user_id, int $license_id, int $dashboard_id, string $task_id ): void {
		$entries = self::load( $user_id );
		$kept    = array_values(
			array_filter(
				$entries,
				static fn( $e ) => ! ( $e['task_id'] === $task_id && $e['license'] === $license_id && $e['dashboard'] === $dashboard_id )
			)
		);

		if ( count( $kept ) !== count( $entries ) ) {
			self::save( $user_id, $kept );
		}
	}

	/**
	 * Forgets every task of a dashboard, for every user.
	 *
	 * @param int $license_id   License.
	 * @param int $dashboard_id Dashboard.
	 */
	public static function forget_dashboard( $license_id, $dashboard_id ): void {
		$license_id   = (int) $license_id;
		$dashboard_id = (int) $dashboard_id;

		$users = get_users(
			array(
				'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => -1,
			)
		);

		foreach ( $users as $user_id ) {
			$entries = self::load( (int) $user_id );
			$kept    = array_values(
				array_filter(
					$entries,
					static fn( $e ) => ! ( $e['license'] === $license_id && $e['dashboard'] === $dashboard_id )
				)
			);

			if ( count( $kept ) !== count( $entries ) ) {
				self::save( (int) $user_id, $kept );
			}
		}
	}

	/**
	 * A user's live, well-formed entries. Expired or odd ones are dropped and the meta rewritten.
	 *
	 * @param int $user_id User.
	 * @return array<int, array{license: int, dashboard: int, kind: string, task_id: string, started: int}>
	 */
	private static function load( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META, true );
		$out = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $e ) {
				if ( ! is_array( $e ) || ! isset( $e['task_id'], $e['started'], $e['license'], $e['dashboard'] ) || ! is_string( $e['task_id'] ) || '' === $e['task_id'] || ! is_numeric( $e['started'] ) || ! is_numeric( $e['license'] ) || ! is_numeric( $e['dashboard'] ) ) {
					continue;
				}

				if ( (int) $e['started'] < time() - DAY_IN_SECONDS ) {
					continue;
				}

				$out[] = array(
					'license'   => (int) $e['license'],
					'dashboard' => (int) $e['dashboard'],
					'kind'      => isset( $e['kind'] ) && is_string( $e['kind'] ) ? $e['kind'] : '',
					'task_id'   => $e['task_id'],
					'started'   => (int) $e['started'],
				);
			}
		}

		if ( '' !== $raw && $raw !== $out ) {
			self::save( $user_id, $out );
		}

		return $out;
	}

	/**
	 * Stores entries.
	 *
	 * @param int                       $user_id User.
	 * @param array<int, array<string, mixed>> $entries Entries.
	 */
	private static function save( int $user_id, array $entries ): void {
		if ( array() === $entries ) {
			delete_user_meta( $user_id, self::META );
			return;
		}

		update_user_meta( $user_id, self::META, $entries );
	}
}
