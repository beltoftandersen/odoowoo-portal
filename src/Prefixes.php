<?php
/**
 * Order prefixes already used by a customer's dashboards.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

use OdooWooPortal\Django\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Django's own list covers one tenant only, and a customer may hold several licenses, so the
 * portal reads the dashboards of every license of the user, and the prefix of each.
 */
final class Prefixes {

	public const TTL       = 60;
	public const MAX_READS = 20;

	/**
	 * The prefixes of the user's other dashboards (every license), flat, without the one edited.
	 *
	 * @param int $user_id      User.
	 * @param int $dashboard_id Dashboard being edited (its own prefix is not "in use").
	 * @return array<int, string>
	 */
	public static function in_use( int $user_id, int $dashboard_id ): array {
		$all  = self::all( $user_id );
		$used = array();

		foreach ( $all as $id => $prefix ) {
			if ( (int) $id !== $dashboard_id && '' !== $prefix ) {
				$used[] = $prefix;
			}
		}

		$used = array_values( array_unique( $used ) );

		sort( $used );

		return $used;
	}

	/**
	 * Forgets the cache of a user (after any write).
	 *
	 * @param int $user_id User.
	 */
	public static function forget( int $user_id ): void {
		delete_transient( self::key( $user_id ) );
	}

	/**
	 * Transient key.
	 *
	 * @param int $user_id User.
	 */
	private static function key( int $user_id ): string {
		return 'odoowoo_portal_prefixes_' . $user_id;
	}

	/**
	 * Dashboard id => prefix, cached.
	 *
	 * @param int $user_id User.
	 * @return array<int, string>
	 */
	private static function all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$cached = get_transient( self::key( $user_id ) );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map   = array();
		$reads = 0;

		foreach ( Context::licenses_for_user( $user_id ) as $license ) {
			$ctx = Context::for_user( $user_id, (int) $license['id'] );

			if ( null === $ctx || 'owner' !== $ctx->mode() || ! $ctx->calls_allowed() || ! in_array( $ctx->state(), array( 'ready', 'paused' ), true ) ) {
				continue;
			}

			if ( $reads >= self::MAX_READS ) {
				break;
			}

			++$reads;
			$list = Client::request( $ctx, 'GET', 'dashboards/' );

			if ( ! $list->ok() ) {
				continue;
			}

			foreach ( $list->data() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_numeric( $row['id'] ) ) {
					continue;
				}

				if ( $reads >= self::MAX_READS ) {
					break;
				}

				++$reads;
				$keys = Client::request( $ctx, 'GET', 'dashboards/' . (int) $row['id'] . '/woo-keys/' );
				$data = $keys->ok() ? $keys->data() : array();

				if ( isset( $data['prefix'] ) && is_string( $data['prefix'] ) ) {
					$map[ (int) $row['id'] ] = $data['prefix'];
				}
			}
		}

		set_transient( self::key( $user_id ), $map, self::TTL );

		return $map;
	}
}
