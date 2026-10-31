<?php
/**
 * The readable address part of a store dashboard.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Account;

use BeltoftServices\Locks;
use BeltoftServices\Resource;
use OdooWooPortal\Api\Routes;
use OdooWooPortal\Context;

defined( 'ABSPATH' ) || exit;

/**
 * A dashboard's page is `/my-account/apps/<service>/<dashboard>/` and `/<dashboard>/<tab>/`. The dashboard part is a slug of its
 * name (lowercase, sanitize_title style), unique within the tenant: a clash gets -2, -3. Django holds the dashboards and
 * their numeric ids; the portal remembers the slug of each in the service's resource (meta `ow_dashboard_slugs`, a list of
 * id and slug) the first time the dashboards are read, and never changes it afterwards: a renamed dashboard keeps its address.
 * A dashboard that has no usable name (empty, or made of digits only, which would read as an id) has no slug and its
 * address uses the number. A slug only ever resolves among the tenant of the license in the address, which Context has
 * already checked belongs to the viewer, so another customer's dashboard is simply not found.
 */
final class Dashboards {

	public const META = 'ow_dashboard_slugs';

	private const MAX = 60;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'odoowoo_portal_dashboard_deleted', array( __CLASS__, 'deleted' ), 10, 2 );
	}

	/**
	 * The remembered slugs of a license's tenant: dashboard id => slug.
	 *
	 * @param int $license_id License id.
	 * @return array<int, string>
	 */
	public static function stored( int $license_id ): array {
		$resource = Resource::for_license( $license_id );

		return $resource ? self::read( $resource ) : array();
	}

	/**
	 * The slug of a dashboard, '' when it has none yet (its address then uses the number).
	 *
	 * @param int $license_id   License id.
	 * @param int $dashboard_id Dashboard id.
	 */
	public static function slug( int $license_id, int $dashboard_id ): string {
		return self::stored( $license_id )[ $dashboard_id ] ?? '';
	}

	/**
	 * The dashboard a slug stands for in a license's tenant, 0 when none.
	 *
	 * @param int    $license_id License id.
	 * @param string $slug       Slug from the address.
	 */
	public static function id_for( int $license_id, string $slug ): int {
		$id = array_search( strtolower( $slug ), self::stored( $license_id ), true );

		return false === $id ? 0 : (int) $id;
	}

	/**
	 * A name as a slug, '' when nothing usable is left or only digits are (they would read as an id).
	 *
	 * @param string $name Dashboard name.
	 */
	public static function base( string $name ): string {
		$slug = trim( substr( sanitize_title( str_replace( '_', '-', strtolower( trim( $name ) ) ) ), 0, self::MAX ), '-' );

		return '' === $slug || ctype_digit( $slug ) ? '' : $slug;
	}

	/**
	 * Gives every dashboard of a list a slug (oldest first, so the first of a name keeps the plain one) and forgets the slugs
	 * of dashboards that are gone. Done under a lock per tenant, written only when something changed.
	 *
	 * @param Context                          $ctx  Owner's context.
	 * @param array<int, array<string, mixed>> $rows Django's dashboards: id and name.
	 * @return array<int, string> Dashboard id => slug, for the dashboards that have one.
	 */
	public static function sync( Context $ctx, array $rows ): array {
		$license_id = (int) $ctx->license()['id'];
		$resource   = Resource::for_license( $license_id );

		if ( ! $resource || 'owner' !== $ctx->mode() ) {
			return array();
		}

		$names = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_numeric( $row['id'] ) && (int) $row['id'] > 0 ) {
				$names[ (int) $row['id'] ] = isset( $row['name'] ) && is_string( $row['name'] ) ? $row['name'] : '';
			}
		}

		ksort( $names );

		$map = self::read( $resource );

		if ( ! self::needs_work( $map, $names ) ) {
			return $map;
		}

		list( $held, $result ) = Locks::with(
			Locks::name( 'owdash', $license_id ),
			5,
			static function () use ( $license_id, $names ): array {
				// Another request may have done it while this one waited.
				$fresh = Resource::for_license( $license_id );

				if ( ! $fresh ) {
					return array();
				}

				$old = self::read( $fresh );
				$map = array_intersect_key( $old, $names );

				foreach ( $names as $id => $name ) {
					if ( isset( $map[ $id ] ) ) {
						continue;
					}

					$base = self::base( $name );

					if ( '' === $base ) {
						continue;
					}

					$map[ $id ] = self::unique( $base, $map );
				}

				if ( $map !== $old ) {
					$fresh->set( array( self::META => self::write( $map ) ) );
				}

				return $map;
			}
		);

		return $held && is_array( $result ) ? $result : $map;
	}

	/**
	 * Is there a dashboard without a slug (that could have one), or a slug without a dashboard?
	 *
	 * @param array<int, string> $map   Slugs by id.
	 * @param array<int, string> $names Names by id.
	 */
	private static function needs_work( array $map, array $names ): bool {
		if ( array_diff_key( $map, $names ) ) {
			return true;
		}

		foreach ( $names as $id => $name ) {
			if ( ! isset( $map[ $id ] ) && '' !== self::base( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The first of base, base-2, base-3 that no dashboard of the tenant holds.
	 *
	 * @param string             $base Wanted slug.
	 * @param array<int, string> $map  Slugs held.
	 */
	private static function unique( string $base, array $map ): string {
		$taken = array_flip( $map );

		for ( $n = 1; $n < 10000; $n++ ) {
			$slug = 1 === $n ? $base : substr( $base, 0, self::MAX - strlen( (string) $n ) - 1 ) . '-' . $n;

			if ( ! isset( $taken[ $slug ] ) ) {
				return $slug;
			}
		}

		return $base . '-' . wp_generate_password( 6, false, false );
	}

	/**
	 * Reads the dashboards from Django (one request per page view, shared with the list card's summary) so every one has
	 * its slug when asked for. Does nothing for a tenant that cannot be asked.
	 *
	 * @param Context $ctx Owner's context.
	 * @return array<int, array<string, mixed>> Django's dashboards (id, name, enabled, created_at).
	 */
	public static function listing( Context $ctx ): array {
		$summary = Routes::license_summary( $ctx );

		return isset( $summary['dashboards'] ) && is_array( $summary['dashboards'] ) ? $summary['dashboards'] : array();
	}

	/**
	 * The dashboard an address part stands for in the license's tenant: a slug, or a number that is one of the tenant's
	 * dashboards. 0 when it is none (an unknown name and someone else's dashboard look the same).
	 *
	 * @param Context $ctx     Owner's context.
	 * @param string  $segment Part of the address.
	 */
	public static function resolve( Context $ctx, string $segment ): int {
		$license_id = (int) $ctx->license()['id'];
		$segment    = strtolower( $segment );

		if ( '' === $segment ) {
			return 0;
		}

		if ( ! ctype_digit( $segment ) ) {
			$id = self::id_for( $license_id, $segment );

			if ( $id > 0 ) {
				return $id;
			}
		}

		// Not remembered yet (or a number): ask the tenant, which also remembers the slugs.
		$ids = array_map( static fn( $row ): int => (int) ( $row['id'] ?? 0 ), array_filter( self::listing( $ctx ), 'is_array' ) );

		if ( ctype_digit( $segment ) ) {
			return in_array( (int) $segment, $ids, true ) ? (int) $segment : 0;
		}

		$id = self::id_for( $license_id, $segment );

		return in_array( $id, $ids, true ) ? $id : 0;
	}

	/**
	 * A dashboard was deleted: its slug is free again.
	 *
	 * @param mixed $license_id   License id.
	 * @param mixed $dashboard_id Dashboard id.
	 */
	public static function deleted( $license_id, $dashboard_id ): void {
		$resource = Resource::for_license( (int) $license_id );

		if ( ! $resource ) {
			return;
		}

		$map = self::read( $resource );

		if ( isset( $map[ (int) $dashboard_id ] ) ) {
			unset( $map[ (int) $dashboard_id ] );
			$resource->set( array( self::META => self::write( $map ) ) );
		}
	}

	/**
	 * The slugs a resource holds.
	 *
	 * @param Resource $resource Resource.
	 * @return array<int, string>
	 */
	private static function read( Resource $resource ): array {
		$rows = $resource->get( self::META );
		$map  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['slug'] ) && is_numeric( $row['id'] ) && is_string( $row['slug'] ) && '' !== $row['slug'] ) {
				$map[ (int) $row['id'] ] = $row['slug'];
			}
		}

		return $map;
	}

	/**
	 * The stored form of the slugs: a list of id and slug.
	 *
	 * @param array<int, string> $map Slugs by id.
	 * @return array<int, array{id: int, slug: string}>
	 */
	private static function write( array $map ): array {
		$rows = array();

		foreach ( $map as $id => $slug ) {
			$rows[] = array( 'id' => (int) $id, 'slug' => $slug );
		}

		return $rows;
	}
}
