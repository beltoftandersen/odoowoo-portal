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
		return self::status( $user_id, $dashboard_id )['used'];
	}

	/**
	 * Like in_use(), and whether the read was partial (a failed read, or more dashboards than
	 * MAX_READS allows): a prefix shown as free may then still be taken.
	 *
	 * @param int $user_id      User.
	 * @param int $dashboard_id Dashboard being edited.
	 * @return array{used: array<int, string>, partial: bool}
	 */
	public static function status( int $user_id, int $dashboard_id ): array {
		list( $all, $complete ) = self::all( $user_id );
		$used                   = array();

		foreach ( $all as $id => $prefix ) {
			if ( (int) $id !== $dashboard_id && '' !== $prefix ) {
				$used[] = $prefix;
			}
		}

		$used = array_values( array_unique( $used ) );

		sort( $used );

		return array(
			'used'    => $used,
			'partial' => ! $complete,
		);
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
	 * Dashboard id => prefix, cached, and whether every dashboard was read (a cached map always was).
	 *
	 * @param int $user_id User.
	 * @return array{0: array<int, string>, 1: bool}
	 */
	private static function all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array( array(), true );
		}

		$cached = get_transient( self::key( $user_id ) );

		if ( is_array( $cached ) ) {
			return array( $cached, true );
		}

		$map      = array();
		$reads    = 0;
		$complete = true;

		foreach ( Context::licenses_for_user( $user_id ) as $license ) {
			$ctx = Context::for_user( $user_id, (int) $license['id'] );

			if ( null === $ctx || 'owner' !== $ctx->mode() || ! $ctx->calls_allowed() || ! in_array( $ctx->state(), array( 'ready', 'paused' ), true ) ) {
				continue;
			}

			if ( $reads >= self::MAX_READS ) {
				$complete = false;
				break;
			}

			++$reads;
			$list = Client::request( $ctx, 'GET', 'dashboards/' );

			if ( ! $list->ok() ) {
				$complete = false;
				continue;
			}

			foreach ( $list->data() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_numeric( $row['id'] ) ) {
					continue;
				}

				if ( $reads >= self::MAX_READS ) {
					$complete = false;
					break;
				}

				++$reads;
				$keys = Client::request( $ctx, 'GET', 'dashboards/' . (int) $row['id'] . '/woo-keys/' );
				$data = $keys->ok() ? $keys->data() : array();

				if ( ! $keys->ok() ) {
					$complete = false;
				} elseif ( isset( $data['prefix'] ) && is_string( $data['prefix'] ) ) {
					$map[ (int) $row['id'] ] = $data['prefix'];
				}
			}
		}

		// A result with a failed read in it is not remembered: the next call tries again.
		if ( $complete ) {
			set_transient( self::key( $user_id ), $map, self::TTL );
		}

		return array( $map, $complete );
	}
}
