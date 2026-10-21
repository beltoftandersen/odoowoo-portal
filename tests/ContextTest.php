<?php
/**
 * Context: ownership, tenant, mode, state and notices.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;
use OdooWooPortal\Context;
use OdooWooPortal\Support;

/**
 * Who may see which license, and what the app may do with it.
 */
final class ContextTest extends TestCase {

	private const PAUSED = 'Your OdooWoo plan is paused. Renew in My Account → Subscriptions';

	private function revoked_license(): array {
		$license = $this->odoowoo_license();
		Licenses::update( (int) $license['id'], array( 'status' => 'revoked' ) );

		return $license;
	}

	public function set_up(): void {
		parent::set_up();
		Context::reset();
	}

	public function test_no_usable_license_means_no_context_and_no_http(): void {
		$this->fake_django( static fn() => array( 200, array() ) );

		$nobody = self::factory()->user->create();
		$this->assertNull( Context::for_user( $nobody, 1 ) );
		$this->assertNull( Context::for_user( $nobody, 0 ) );
		$this->assertSame( array(), Context::licenses_for_user( $nobody ) );

		$other = $this->odoowoo_license( array( 'product_slug' => 'some-plugin' ) );
		$this->assertNull( Context::for_user( (int) $other['user_id'], (int) $other['id'] ) );
		$this->assertSame( array(), Context::licenses_for_user( (int) $other['user_id'] ) );

		$revoked = $this->revoked_license();
		$this->assertNull( Context::for_user( (int) $revoked['user_id'], (int) $revoked['id'] ) );
		$this->assertSame( array(), Context::licenses_for_user( (int) $revoked['user_id'] ) );

		$closed = $this->odoowoo_license();
		Resource::for_license( (int) $closed['id'] )->close();
		$context = Context::for_user( (int) $closed['user_id'], (int) $closed['id'] );
		$this->assertSame( 'unavailable', $context->state() );
		$this->assertSame( '', $context->tenant() );
		$this->assertFalse( $context->calls_allowed() );
		$this->assertFalse( $context->can_write() );
		$this->assertSame( 'OdooWoo is not available for this license. Please contact support.', $context->notice() );

		$this->assertSame( 0, count( $this->django_calls() ) );
	}

	public function test_other_users_license_is_refused(): void {
		$license = $this->odoowoo_license();
		$other   = self::factory()->user->create();

		$this->assertNull( Context::for_user( $other, (int) $license['id'] ) );
		$this->assertInstanceOf( Context::class, Context::for_user( (int) $license['user_id'], (int) $license['id'] ) );
	}

	public function test_email_match_alone_does_not_grant_ownership(): void {
		$owner   = self::factory()->user->create( array( 'user_email' => 'same@example.com' ) );
		$license = $this->odoowoo_license( array( 'email' => 'same@example.com', 'user_id' => $owner ) );
		$guest   = $this->odoowoo_license( array( 'email' => 'guest@example.com', 'user_id' => null ) );
		$twin    = self::factory()->user->create( array( 'user_email' => 'guest@example.com' ) );

		$this->assertNull( Context::for_user( $twin, (int) $guest['id'] ) );
		$this->assertNull( Context::for_user( 0, (int) $guest['id'] ) );
		$this->assertSame( array(), Context::licenses_for_user( $twin ) );
		$this->assertNotNull( Context::for_user( $owner, (int) $license['id'] ) );
	}

	public function test_licenses_for_user_are_newest_first_and_only_odoowoo(): void {
		$user  = self::factory()->user->create();
		$first = $this->odoowoo_license( array( 'user_id' => $user ) );
		$this->odoowoo_license( array( 'user_id' => $user, 'product_slug' => 'other-product' ) );
		$last = $this->odoowoo_license( array( 'user_id' => $user ) );

		$ids = array_map( static fn( $l ) => (int) $l['id'], Context::licenses_for_user( $user ) );

		$this->assertSame( array( (int) $last['id'], (int) $first['id'] ), $ids );
	}

	public function test_state_follows_resource_and_license(): void {
		$license  = $this->odoowoo_license();
		$user     = (int) $license['user_id'];
		$resource = Resource::for_license( (int) $license['id'] );

		$context = Context::for_user( $user, (int) $license['id'] );
		$this->assertSame( 'owner', $context->mode() );
		$this->assertSame( 'ready', $context->state() );
		$this->assertTrue( $context->can_write() );
		$this->assertTrue( $context->calls_allowed() );
		$this->assertSame( '', $context->notice() );
		$this->assertSame( $resource->get( 'uuid' ), $context->tenant() );
		$this->assertSame( (int) $license['id'], (int) $context->license()['id'] );

		foreach ( array( 'requested', 'provisioning' ) as $state ) {
			$resource->set( array( 'state' => $state ) );
			Context::reset();
			$context = Context::for_user( $user, (int) $license['id'] );
			$this->assertSame( 'getting-ready', $context->state() );
			$this->assertFalse( $context->calls_allowed() );
			$this->assertFalse( $context->can_write() );
			$this->assertSame( 'Getting ready…', $context->notice() );
		}

		$resource->set( array( 'state' => 'paused' ) );
		Context::reset();
		$context = Context::for_user( $user, (int) $license['id'] );
		$this->assertSame( 'paused', $context->state() );
		$this->assertTrue( $context->calls_allowed() );
		$this->assertFalse( $context->can_write() );
		$this->assertSame( self::PAUSED, $context->notice() );

		foreach ( array( 'failed', 'deleting', 'deleted' ) as $state ) {
			$resource->set( array( 'state' => $state ) );
			Context::reset();
			$context = Context::for_user( $user, (int) $license['id'] );
			$this->assertSame( 'unavailable', $context->state() );
			$this->assertFalse( $context->calls_allowed() );
		}

		$resource->set( array( 'state' => 'ready' ) );

		foreach ( array( 'suspended', 'expired' ) as $status ) {
			Licenses::update( (int) $license['id'], array( 'status' => $status ) );
			Context::reset();
			$context = Context::for_user( $user, (int) $license['id'] );
			$this->assertSame( 'paused', $context->state(), $status );
			$this->assertSame( self::PAUSED, $context->notice() );
			$this->assertFalse( $context->can_write() );
			$this->assertTrue( $context->calls_allowed(), 'Reads still work.' );
		}
	}

	public function test_license_past_its_expiry_date_is_read_only(): void {
		$license = $this->odoowoo_license();
		Licenses::update( (int) $license['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$context = Context::for_user( (int) $license['user_id'], (int) $license['id'] );

		$this->assertSame( 'paused', $context->state() );
		$this->assertFalse( $context->can_write() );
	}

	public function test_support_user_gets_support_mode(): void {
		$license = $this->odoowoo_license();
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( 'manage_woocommerce' );

		$this->assertNull( Context::for_user( $admin, (int) $license['id'] ), 'No arrival, no access.' );

		Support::grant( $admin, (int) $license['id'] );
		Context::reset();
		$context = Context::for_user( $admin, (int) $license['id'] );
		$this->assertSame( 'support', $context->mode() );
		$this->assertFalse( $context->can_write() );
		$this->assertTrue( $context->calls_allowed() );
		$this->assertSame( 'Viewing as support (read-only)', $context->notice() );

		$revoked = $this->revoked_license();
		Support::grant( $admin, (int) $revoked['id'] );
		$this->assertNull( Context::for_user( $admin, (int) $revoked['id'] ) );

		$other = $this->odoowoo_license( array( 'product_slug' => 'some-plugin' ) );
		Support::grant( $admin, (int) $other['id'] );
		$this->assertNull( Context::for_user( $admin, (int) $other['id'] ) );

		// A session for one license is no access to another.
		$second = $this->odoowoo_license();
		$this->assertNull( Context::for_user( $admin, (int) $second['id'] ) );

		// The session ends.
		update_user_meta( $admin, 'odoowoo_support_until_' . $license['id'], time() - 1 );
		Context::reset();
		$this->assertNull( Context::for_user( $admin, (int) $license['id'] ) );
	}

	public function test_owner_with_support_capability_stays_owner(): void {
		$license = $this->odoowoo_license();
		get_userdata( (int) $license['user_id'] )->add_cap( 'manage_woocommerce' );
		Support::grant( (int) $license['user_id'], (int) $license['id'] );

		$this->assertSame( 'owner', Context::for_user( (int) $license['user_id'], (int) $license['id'] )->mode() );
	}
}
