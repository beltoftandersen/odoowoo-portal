<?php
/**
 * Settings.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use OdooWooPortal\Settings;

/**
 * Defaults and sanitizing.
 */
final class SettingsTest extends TestCase {

	public function test_defaults_and_page_ids_must_be_published_pages(): void {
		$this->assertSame( '', Settings::get( 'connection_id' ) );
		$this->assertSame( 'odoowoo', Settings::get( 'software_slug' ) );
		$this->assertSame( 0, Settings::get( 'list_page_id' ) );
		$this->assertSame( 0, Settings::get( 'dashboard_page_id' ) );
		$this->assertFalse( Settings::get( 'delete_data' ) );

		$published = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$draft     = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$post      = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$clean = Settings::sanitize(
			array(
				'list_page_id'      => (string) $published,
				'dashboard_page_id' => (string) $draft,
				'software_slug'     => '  My Slug ',
				'connection_id'     => 'nope',
				'delete_data'       => '1',
			)
		);

		$this->assertSame( $published, $clean['list_page_id'] );
		$this->assertSame( 0, $clean['dashboard_page_id'], 'A draft is refused.' );
		$this->assertSame( 'my-slug', $clean['software_slug'] );
		$this->assertSame( '', $clean['connection_id'], 'An unknown connection is refused.' );
		$this->assertTrue( $clean['delete_data'] );

		$this->assertSame( 0, Settings::sanitize( array( 'list_page_id' => (string) $post ) )['list_page_id'], 'A post is not a page.' );
		$this->assertSame( 0, Settings::sanitize( array( 'list_page_id' => '999999' ) )['list_page_id'] );
		$this->assertSame( 'odoowoo', Settings::sanitize( array( 'software_slug' => '' ) )['software_slug'] );
		$this->assertFalse( Settings::sanitize( array() )['delete_data'] );

		$this->connection( 'odoowoo' );
		$this->assertSame( 'odoowoo', Settings::sanitize( array( 'connection_id' => 'odoowoo' ) )['connection_id'] );
	}

	public function test_rejections_are_reported_to_the_admin(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';

		global $wp_settings_errors;
		$wp_settings_errors = array();

		$draft = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );

		Settings::sanitize( array( 'list_page_id' => (string) $draft, 'connection_id' => 'nope' ) );

		$codes = wp_list_pluck( $wp_settings_errors, 'code' );
		$this->assertContains( 'odoowoo_portal_list_page_id', $codes );
		$this->assertContains( 'odoowoo_portal_connection_id', $codes );
		$this->assertNotContains( 'odoowoo_portal_dashboard_page_id', $codes, 'An empty choice is not a rejection.' );

		$wp_settings_errors = array();
		Settings::sanitize( array() );
		$this->assertSame( array(), $wp_settings_errors );
	}

	public function test_screen_never_shows_the_connection_secret(): void {
		$this->connection( 'odoowoo' );
		$blob = (string) \BeltoftServices\Http\Connections::all()['odoowoo']['secret'];

		$this->assertNotSame( '', $blob );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Settings::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'odoowoo-connection', $html );
		$this->assertStringContainsString( 'OdooWoo', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringNotContainsString( $blob, $html );
	}

	public function test_screen_renders_both_page_dropdowns_with_the_saved_page_selected(): void {
		$list      = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Apps' ) );
		$dashboard = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'One app' ) );
		$this->settings( array( 'list_page_id' => $list, 'dashboard_page_id' => $dashboard ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Settings::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="odoowoo_portal_settings[list_page_id]"', $html );
		$this->assertStringContainsString( 'id="odoowoo-list-page"', $html );
		$this->assertStringContainsString( 'name="odoowoo_portal_settings[dashboard_page_id]"', $html );
		$this->assertStringContainsString( 'id="odoowoo-dashboard-page"', $html );
		$this->assertMatchesRegularExpression( '/<option[^>]*value="' . $list . '"[^>]*selected(?:="selected")?[^>]*>Apps</', $html );
		$this->assertMatchesRegularExpression( '/<option[^>]*value="' . $dashboard . '"[^>]*selected(?:="selected")?[^>]*>One app</', $html );
		$this->assertStringContainsString( 'value="0"', $html );
	}
}
