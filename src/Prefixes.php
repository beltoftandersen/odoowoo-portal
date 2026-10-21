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
	 * The prefixes of the user's other dashboards (every license), flat, without the one edited, and whether the read was partial (a failed read, or more dashboards than
	 * MAX_READS allows): a prefix shown as free may then still be taken.
	 *
	 * @param int $user_id      User.
	 * @param int $dashboard_id Dashboard being edited.
	 * @param int $license_id   License being edited: Django's own list covers its tenant, so a gap in its read does not make the result partial. 0 when unknown.
	 * @return array{used: array<int, string>, partial: bool}
	 */
	public static function status( int $user_id, int $dashboard_id, int $license_id = 0 ): array {
		list( $all, $unread ) = self::all( $user_id );
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
			'partial' => array() !== array_diff( $unread, array( $license_id ) ),
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
	 * Dashboard id => prefix, cached, and the licenses whose dashboards were not all read (none
	 * for a cached map).
	 *
	 * @param int $user_id User.
	 * @return array{0: array<int, string>, 1: array<int, int>}
	 */
	private static function all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array( array(), array() );
		}

		$cached = get_transient( self::key( $user_id ) );

		if ( is_array( $cached ) ) {
			return array( $cached, array() );
		}

		$map      = array();
		$reads  = 0;
		$unread = array();

		foreach ( Context::licenses_for_user( $user_id ) as $license ) {
			$ctx = Context::for_user( $user_id, (int) $license['id'] );

			if ( null === $ctx || 'owner' !== $ctx->mode() || ! $ctx->calls_allowed() || ! in_array( $ctx->state(), array( 'ready', 'paused' ), true ) ) {
				continue;
			}

			$license_id = (int) $license['id'];

			if ( $reads >= self::MAX_READS ) {
				$unread[] = $license_id;
				continue;
			}

			++$reads;
			$list = Client::request( $ctx, 'GET', 'dashboards/' );

			if ( ! $list->ok() ) {
				$unread[] = $license_id;
				continue;
			}

			foreach ( $list->data() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_numeric( $row['id'] ) ) {
					continue;
				}

				if ( $reads >= self::MAX_READS ) {
					$unread[] = $license_id;
					break;
				}

				++$reads;
				$keys = Client::request( $ctx, 'GET', 'dashboards/' . (int) $row['id'] . '/woo-keys/' );
				$data = $keys->ok() ? $keys->data() : array();

				if ( ! $keys->ok() ) {
					$unread[] = $license_id;
				} elseif ( isset( $data['prefix'] ) && is_string( $data['prefix'] ) ) {
					$map[ (int) $row['id'] ] = $data['prefix'];
				}
			}
		}

		// A result with a failed read in it is not remembered: the next call tries again.
		$unread = array_values( array_unique( $unread ) );

		if ( array() === $unread ) {
			set_transient( self::key( $user_id ), $map, self::TTL );
		}

		return array( $map, $unread );
	}
}
