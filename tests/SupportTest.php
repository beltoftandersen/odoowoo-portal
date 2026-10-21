<?php
/**
 * Support visits.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftLicenses\Events;
use OdooWooPortal\Context;
use OdooWooPortal\Support;
use OdooWooPortal\Urls;

/**
 * The "View as support" link and the visit history.
 */
final class SupportTest extends TestCase {

	private function support_user(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $id )->add_cap( 'manage_woocommerce' );

		return $id;
	}

	private function visits( int $license_id ): int {
		return count( array_filter( Events::for_license( $license_id ), static fn( $e ) => 'support_view' === $e['type'] ) );
	}

	public function set_up(): void {
		parent::set_up();
		Context::reset();
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_support_user_is_read_only_and_visit_is_logged(): void {
		$this->fake_django( static fn() => array( 200, array() ) );

		$license = $this->odoowoo_license();
		$admin   = $this->support_user();
		$this->as_user( $admin );

		$this->assertNull( Context::for_user( $admin, (int) $license['id'] ) );

		$query = array( 'license' => (string) $license['id'], 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $license['id'] ) );

		$this->assertTrue( Support::arrived( $query, $admin ) );
		$this->assertTrue( Support::arrived( $query, $admin ) );
		$this->assertSame( 2, $this->visits( (int) $license['id'] ), 'No dedupe.' );

		$context = Context::for_user( $admin, (int) $license['id'] );
		$this->assertSame( 'support', $context->mode() );
		$this->assertFalse( $context->can_write() );

		// Loading the context, and plain visits without the link, log nothing.
		Context::for_user( $admin, (int) $license['id'] );
		$this->assertFalse( Support::arrived( array( 'license' => (string) $license['id'] ), $admin ) );
		$this->assertSame( 2, $this->visits( (int) $license['id'] ) );
		$this->assertSame( 0, count( $this->django_calls() ) );
	}

	public function test_visit_needs_nonce_capability_and_a_context(): void {
		$license = $this->odoowoo_license();
		$admin   = $this->support_user();
		$id      = (int) $license['id'];
		$good    = array( 'license' => (string) $id, 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $id ) );
		$this->as_user( $admin );

		$this->assertFalse( Support::arrived( array_merge( $good, array( '_wpnonce' => 'bad' ) ), $admin ) );
		$this->assertFalse( Support::arrived( array( 'license' => (string) $id, 'odoowoo_support' => '1' ), $admin ) );

		// The owner opening the link is not a support visit.
		$owner = (int) $license['user_id'];
		$this->as_user( $owner );
		$nonce = array_merge( $good, array( '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $id ) ) );
		$this->assertFalse( Support::arrived( $nonce, $owner ) );

		// A customer who is not support.
		$stranger = self::factory()->user->create();
		$this->as_user( $stranger );
		$nonce = array_merge( $good, array( '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $id ) ) );
		$this->assertFalse( Support::arrived( $nonce, $stranger ) );

		$this->assertSame( 0, $this->visits( $id ) );
	}

	public function test_nonce_of_another_license_or_user_is_refused(): void {
		$a     = $this->odoowoo_license();
		$b     = $this->odoowoo_license();
		$admin = $this->support_user();
		$other = $this->support_user();
		$this->as_user( $admin );

		$query = array( 'license' => (string) $b['id'], 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $a['id'] ) );
		$this->assertFalse( Support::arrived( $query, $admin ) );
		$this->assertNull( Context::for_user( $admin, (int) $b['id'] ) );

		// Another user's nonce, and a user id that is not the acting one.
		$query = array( 'license' => (string) $a['id'], 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $a['id'] ) );
		$this->assertFalse( Support::arrived( $query, $other ) );
		$this->as_user( $other );
		$this->assertFalse( Support::arrived( $query, $other ) );
		$this->assertNull( Context::for_user( $other, (int) $a['id'] ) );
		$this->assertSame( 0, $this->visits( (int) $a['id'] ) + $this->visits( (int) $b['id'] ) );
	}

	public function test_arrival_gives_a_session_that_expires(): void {
		$license = $this->odoowoo_license();
		$admin   = $this->support_user();
		$this->as_user( $admin );
		$id    = (int) $license['id'];
		$query = array( 'license' => (string) $id, 'odoowoo_support' => '1', '_wpnonce' => wp_create_nonce( 'odoowoo_support_' . $id ) );

		$this->assertTrue( Support::arrived( $query, $admin ) );
		$this->assertTrue( Support::has_session( $admin, $id ) );
		$this->assertEqualsWithDelta( time() + 2 * HOUR_IN_SECONDS, (int) get_user_meta( $admin, 'odoowoo_support_until_' . $id, true ), 5 );
		$this->assertSame( 'support', Context::for_user( $admin, $id )->mode() );

		update_user_meta( $admin, 'odoowoo_support_until_' . $id, time() - 1 );
		Context::reset();
		$this->assertFalse( Support::has_session( $admin, $id ) );
		$this->assertNull( Context::for_user( $admin, $id ) );
	}

	public function test_url_carries_license_flag_and_nonce(): void {
		$license = $this->odoowoo_license();
		$admin   = $this->support_user();
		$this->as_user( $admin );

		$url = Support::url( (int) $license['id'] );
		$this->assertStringStartsWith( Urls::list(), $url );

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$this->assertSame( (string) $license['id'], $args['license'] );
		$this->assertSame( '1', $args['odoowoo_support'] );
		$this->assertSame( 1, wp_verify_nonce( $args['_wpnonce'], 'odoowoo_support_' . $license['id'] ) );
	}

	public function test_admin_screen_shows_the_link_for_odoowoo_licenses_only(): void {
		$license = $this->odoowoo_license();
		$other   = $this->odoowoo_license( array( 'product_slug' => 'some-plugin' ) );
		$this->as_user( $this->support_user() );

		ob_start();
		do_action( 'beltoft_licenses_admin_license', $license );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'View as support', $html );
		$this->assertStringContainsString( 'odoowoo_support=1', html_entity_decode( $html ) );

		ob_start();
		do_action( 'beltoft_licenses_admin_license', $other );
		$this->assertStringNotContainsString( 'View as support', (string) ob_get_clean() );

		$this->as_user( self::factory()->user->create() );
		ob_start();
		do_action( 'beltoft_licenses_admin_license', $license );
		$this->assertStringNotContainsString( 'View as support', (string) ob_get_clean() );
	}
}
