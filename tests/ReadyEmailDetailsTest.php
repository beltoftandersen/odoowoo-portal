<?php
/**
 * The next step in the OdooWoo ready email.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use BeltoftServices\Resource;
use OdooWooPortal\Urls;

/**
 * The ready email of an OdooWoo license says where to go and what to do first.
 */
final class ReadyEmailDetailsTest extends TestCase {

	public function test_odoowoo_ready_email_gets_dashboard_link_and_next_step(): void {
		$license = $this->odoowoo_license();
		$r       = Resource::for_license( (int) $license['id'] );

		$details = apply_filters( 'beltoft_services_email_details', array(), 'beltoft_services_ready', $r );

		$this->assertSame( Urls::license_list( (int) $license['id'] ), $details['Open your dashboard'] );
		$this->assertStringContainsString( 'connect your WooCommerce store and your Odoo', $details['Next step'] );
	}

	public function test_other_emails_and_other_products_are_left_alone(): void {
		$license = $this->odoowoo_license();
		$r       = Resource::for_license( (int) $license['id'] );

		$this->assertSame( array(), apply_filters( 'beltoft_services_email_details', array(), 'beltoft_services_paused', $r ) );

		$other = \BeltoftLicenses\Licenses::create(
			array(
				'product_slug'    => 'something-else',
				'email'           => 'customer@example.com',
				'user_id'         => (int) $license['user_id'],
				'max_activations' => 1,
				'source'          => 'order',
			)
		);
		$this->assertIsArray( $other );
		$fake = new class( $other ) {
			private $license;
			public function __construct( $license ) {
				$this->license = $license;
			}
			public function license() {
				return $this->license;
			}
		};

		$this->assertSame( array( 'x' => 'y' ), apply_filters( 'beltoft_services_email_details', array( 'x' => 'y' ), 'beltoft_services_ready', $fake ) );
	}
}
