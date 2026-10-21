<?php
/**
 * Release metadata: version strings and the readme.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

/**
 * The version is written in four places; they must agree.
 */
final class ReleaseTest extends TestCase {

	private function read( string $relative ): string {
		return (string) file_get_contents( ODOOWOO_PORTAL_PATH . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_version_is_0_1_0_everywhere(): void {
		$this->assertSame( '0.1.0', ODOOWOO_PORTAL_VERSION );
		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.1\.0$/m', $this->read( 'odoowoo-portal.php' ) );

		$readme = $this->read( 'readme.txt' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0\.1\.0$/m', $readme );
		$this->assertStringContainsString( "== Changelog ==\n\n= 0.1.0 =\n", $readme );

		$this->assertStringContainsString( 'Project-Id-Version: OdooWoo Portal 0.1.0\n', $this->read( 'languages/odoowoo-portal.pot' ) );
	}

	public function test_readme_lists_every_hook_the_plugin_offers(): void {
		$readme = $this->read( 'readme.txt' );
		$found  = array();
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( ODOOWOO_PORTAL_PATH . 'src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			preg_match_all( "/(apply_filters|do_action)\( '(odoowoo_portal_[a-z_]+)'/", (string) file_get_contents( $file->getPathname() ), $m, PREG_SET_ORDER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( $m as $hit ) {
				$found[ $hit[2] ] = $hit[1];
			}
		}

		$this->assertArrayHasKey( 'odoowoo_portal_bricks_available', $found, 'The scan finds the hooks.' );
		$this->assertGreaterThanOrEqual( 6, count( $found ) );

		foreach ( $found as $hook => $kind ) {
			$this->assertStringContainsString( '`' . $hook . '`', $readme, $hook . ' is listed in the readme.' );
		}
	}
}
