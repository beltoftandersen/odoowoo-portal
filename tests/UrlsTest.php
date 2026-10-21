<?php
/**
 * URLs and tabs.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Tabs;
use OdooWooPortal\Urls;

/**
 * Links to the app's pages.
 */
final class UrlsTest extends TestCase {

	public function test_dashboard_url_carries_license_dashboard_tab(): void {
		$list      = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'odoowoo' ) );
		$dashboard = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'odoowoo-dashboard' ) );
		$this->settings( array( 'list_page_id' => $list, 'dashboard_page_id' => $dashboard ) );
		$this->set_permalink_structure( '/%postname%/' );

		$this->assertSame( get_permalink( $dashboard ) . '?license=7&dashboard=9&tab=mappings', Urls::dashboard( 7, 9, 'mappings' ) );
		$this->assertSame( get_permalink( $dashboard ) . '?license=7&dashboard=9&tab=connections', Urls::dashboard( 7, 9 ) );
		$this->assertSame( get_permalink( $list ), Urls::list() );
		$this->assertSame( get_permalink( $list ) . '?license=7', Urls::license_list( 7 ) );
	}

	public function test_unknown_tab_becomes_connections(): void {
		$this->assertSame( 'connections', Tabs::valid( 'zzz' ) );
		$this->assertSame( 'connections', Tabs::valid( '' ) );

		foreach ( Tabs::SLUGS as $tab ) {
			$this->assertSame( $tab, Tabs::valid( $tab ) );
		}

		$this->assertSame( array( 'connections', 'mappings', 'gift-cards', 'sync', 'logs' ), Tabs::SLUGS );
		$this->assertStringContainsString( 'tab=connections', Urls::dashboard( 1, 2, 'zzz' ) );
	}

	public function test_base_helpers_sign_and_record(): void {
		$this->connection();
		$license = $this->odoowoo_license();
		$tenant  = (string) \BeltoftServices\Resource::for_license( (int) $license['id'] )->get( 'uuid' );

		$this->fake_django( static fn( array $call ) => array( 200, array( 'ok' => true ) ) );

		$connection = apply_filters( 'beltoft_services_connection', null, 'odoowoo' );
		$headers    = \BeltoftServices\Http\Signer::headers( $connection, 'GET', '/api/x?a=1', '', $tenant );
		wp_remote_get( 'http://127.0.0.1:8101/api/x?a=1', array( 'headers' => $headers ) );

		$calls = $this->django_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( '/api/x?a=1', $calls[0]['path_and_query'] );
		$this->assert_signed( $calls[0], $tenant );
	}
}
