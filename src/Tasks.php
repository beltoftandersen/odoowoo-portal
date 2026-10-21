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

		self::mutate(
			$user_id,
			static function ( array $entries ) use ( $license_id, $dashboard_id, $kind, $task_id ): array {
				$entries   = array_values( array_filter( $entries, static fn( $e ) => $e['task_id'] !== $task_id ) );
				$entries[] = array(
					'license'   => $license_id,
					'dashboard' => $dashboard_id,
					'kind'      => $kind,
					'task_id'   => $task_id,
					'started'   => time(),
				);

				return array_slice( $entries, -self::MAX );
			}
		);
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
		self::mutate(
			$user_id,
			static fn( array $entries ): array => array_values(
				array_filter(
					$entries,
					static fn( $e ) => ! ( $e['task_id'] === $task_id && $e['license'] === $license_id && $e['dashboard'] === $dashboard_id )
				)
			)
		);
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
			self::mutate(
				(int) $user_id,
				static fn( array $entries ): array => array_values(
					array_filter(
						$entries,
						static fn( $e ) => ! ( $e['license'] === $license_id && $e['dashboard'] === $dashboard_id )
					)
				)
			);
		}
	}

	/**
	 * A user's live, well-formed entries. Expired or odd ones are dropped and the meta rewritten.
	 *
	 * @param int  $user_id           User.
	 * @param bool $reading_for_write Inside mutate(): do not clean up on the side.
	 * @return array<int, array{license: int, dashboard: int, kind: string, task_id: string, started: int}>
	 */
	private static function load( int $user_id, bool $reading_for_write = false ): array {
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

		if ( '' !== $raw && $raw !== $out && ! $reading_for_write ) {
			self::mutate( $user_id, static fn( array $entries ): array => $entries );
		}

		return $out;
	}

	/**
	 * Changes a user's entries under a short lock: the entries are read again once the lock is
	 * held and the change is applied to that fresh copy, so two tabs starting syncs at once keep
	 * both tasks. The lock is an option row (add_option is atomic); a lock older than 5 seconds
	 * is stale and taken over; after about a second of waiting the change goes ahead without it
	 * (the window left is then the few milliseconds between the read and the write).
	 *
	 * @param int      $user_id User.
	 * @param callable $change  Gets the fresh entries, returns the entries to store.
	 */
	private static function mutate( int $user_id, callable $change ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$lock = self::META . '_lock_' . $user_id;
		$held = false;

		for ( $i = 0; $i < 20; $i++ ) {
			if ( add_option( $lock, time(), '', 'no' ) ) {
				$held = true;
				break;
			}

			wp_cache_delete( $lock, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$since = (int) get_option( $lock, 0 );

			if ( $since < time() - 5 ) {
				delete_option( $lock );
				continue;
			}

			usleep( 50000 );
		}

		try {
			$fresh = self::load( $user_id, true );

			self::save( $user_id, array_values( $change( $fresh ) ) );
		} finally {
			if ( $held ) {
				delete_option( $lock );
			}
		}
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
