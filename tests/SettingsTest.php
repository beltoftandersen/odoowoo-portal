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
}
