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
}
