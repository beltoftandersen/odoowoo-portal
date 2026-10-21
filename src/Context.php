<?php
/**
 * What a user may do with one OdooWoo license.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership, tenant, mode and state of one license. Never calls Django.
 */
final class Context {

	/**
	 * Contexts of this request, by "user:license".
	 *
	 * @var array<string, Context|null>
	 */
	private static $cache = array();

	/**
	 * License row.
	 *
	 * @var array<string, mixed>
	 */
	private $license;

	/**
	 * Resource, if any.
	 *
	 * @var Resource|null
	 */
	private $resource;

	/**
	 * Mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $license  License.
	 * @param Resource|null        $resource Live resource.
	 * @param string               $mode     owner or support.
	 */
	private function __construct( array $license, ?Resource $resource, string $mode ) {
		$this->license  = $license;
		$this->resource = $resource;
		$this->mode     = $mode;
	}

	/**
	 * The context of a user for a license, or null when the user may not see it.
	 *
	 * @param int $user_id    User id.
	 * @param int $license_id License id.
	 */
	public static function for_user( int $user_id, int $license_id ): ?Context {
		$key = $user_id . ':' . $license_id;

		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		self::$cache[ $key ] = self::build( $user_id, $license_id );

		return self::$cache[ $key ];
	}

	/**
	 * Forgets the memoized contexts (tests, long requests).
	 */
	public static function reset(): void {
		self::$cache = array();
	}

	/**
	 * The user's OdooWoo licenses, newest first. Revoked ones are left out.
	 *
	 * @param int $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function licenses_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$rows = array_filter( Licenses::where( 'user_id', $user_id ), array( __CLASS__, 'usable' ) );

		return array_reverse( array_values( $rows ) );
	}

	/**
	 * Which license a page is about: the one the URL names (kept as given; for_user() decides
	 * whether the viewer may have it), else the viewer's newest usable one, else 0.
	 *
	 * @param int $user_id   User id.
	 * @param int $requested License id from ?license=, 0 when absent.
	 */
	public static function page_license_id( int $user_id, int $requested ): int {
		if ( $requested > 0 ) {
			return $requested;
		}

		$own = self::licenses_for_user( $user_id );

		return array() !== $own ? (int) $own[0]['id'] : 0;
	}

	/**
	 * Builds a context.
	 *
	 * @param int $user_id    User id.
	 * @param int $license_id License id.
	 */
	private static function build( int $user_id, int $license_id ): ?Context {
		$license = $license_id > 0 ? Licenses::find( $license_id ) : null;

		if ( ! $license || ! self::usable( $license ) ) {
			return null;
		}

		if ( $user_id > 0 && (int) $license['user_id'] === $user_id ) {
			$mode = 'owner';
		} elseif ( $user_id > 0 && user_can( $user_id, 'manage_woocommerce' ) && Support::has_session( $user_id, $license_id ) ) {
			$mode = 'support';
		} else {
			return null;
		}

		return new self( $license, Resource::for_license( $license_id ), $mode );
	}

	/**
	 * The license a manager may be given a support session for: an OdooWoo license that is not
	 * revoked, the user can manage_woocommerce and is not its owner. Null otherwise.
	 *
	 * @param int $user_id    User id.
	 * @param int $license_id License id.
	 * @return array<string, mixed>|null
	 */
	public static function support_license( int $user_id, int $license_id ): ?array {
		$license = $license_id > 0 ? Licenses::find( $license_id ) : null;

		if ( ! $license || ! self::usable( $license ) || $user_id <= 0 || (int) $license['user_id'] === $user_id || ! user_can( $user_id, 'manage_woocommerce' ) ) {
			return null;
		}

		return $license;
	}

	/**
	 * Is this license an OdooWoo license that is not revoked?
	 *
	 * @param array<string, mixed> $license License.
	 */
	private static function usable( array $license ): bool {
		return (string) Settings::get( 'software_slug' ) === $license['product_slug'] && Licenses::STATUS_REVOKED !== $license['status'];
	}

	/**
	 * The license row.
	 *
	 * @return array<string, mixed>
	 */
	public function license(): array {
		return $this->license;
	}

	/**
	 * The tenant id (resource uuid), empty without a resource.
	 */
	public function tenant(): string {
		return $this->resource && $this->resource->is_valid() ? $this->resource->get( 'uuid' ) : '';
	}

	/**
	 * Owner or support.
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Is the license active (not suspended, expired or revoked)?
	 */
	private function active(): bool {
		return Licenses::STATUS_ACTIVE === Licenses::effective_status( $this->license );
	}

	/**
	 * ready, getting-ready, paused or unavailable.
	 */
	public function state(): string {
		if ( ! $this->resource || ! $this->resource->is_valid() ) {
			return 'unavailable';
		}

		switch ( $this->resource->get( 'state' ) ) {
			case 'requested':
			case 'provisioning':
				return 'getting-ready';
			case 'ready':
				return $this->active() ? 'ready' : 'paused';
			case 'paused':
				return 'paused';
			default:
				return 'unavailable';
		}
	}

	/**
	 * May Django be called at all (reads, delete and verifies)?
	 */
	public function calls_allowed(): bool {
		$resource_state = $this->resource && $this->resource->is_valid() ? $this->resource->get( 'state' ) : '';

		return in_array( $resource_state, array( 'ready', 'paused' ), true );
	}

	/**
	 * May the viewer change things?
	 */
	public function can_write(): bool {
		return 'owner' === $this->mode && 'ready' === $this->state();
	}

	/**
	 * The state notice (paused, getting ready, unavailable), or for a support viewer in a
	 * ready state the support banner, or ''. Contract: the page shows banner() whenever
	 * mode() is support, in addition to notice() (never rely on notice() for the banner).
	 */
	public function notice(): string {
		switch ( $this->state() ) {
			case 'paused':
				return __( 'Your OdooWoo plan is paused. Renew in My Account → Subscriptions', 'odoowoo-portal' );
			case 'getting-ready':
				return __( 'Getting ready…', 'odoowoo-portal' );
			case 'unavailable':
				return __( 'OdooWoo is not available for this license. Please contact support.', 'odoowoo-portal' );
		}

		return 'support' === $this->mode ? $this->banner() : '';
	}

	/**
	 * The support banner, '' for an owner. Shown whenever mode() is support.
	 */
	public function banner(): string {
		return 'support' === $this->mode ? __( 'Viewing as support (read-only)', 'odoowoo-portal' ) : '';
	}
}
