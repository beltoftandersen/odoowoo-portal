<?php
/**
 * The index of the spec section 8 mistakes that are the plugin's: each has a named regression
 * test, and the test asserts something.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Names only: no WordPress state is touched.
 */
final class MistakesIndexTest extends PhpUnitTestCase {

	/**
	 * The named tests.
	 *
	 * @var string[]
	 */
	private const MISTAKES = array(
		'test_odoowoo_mistake_progress_reads_top_level_current_total',
		'test_odoowoo_mistake_skipped_and_failed_not_completed',
		'test_odoowoo_mistake_polling_stops_on_unknown_and_finished',
		'test_odoowoo_mistake_running_task_resumed_after_reload',
		'test_odoowoo_mistake_callback_key_box_renders_when_key_exists',
		'test_odoowoo_mistake_prefixes_in_use_marked',
		'test_odoowoo_mistake_tax_refresh_empty_reply',
		'test_odoowoo_mistake_price_rows_keep_saved_pricelist',
		'test_odoowoo_mistake_required_mapping_fields_refused',
		'test_odoowoo_mistake_dashboard_name_shown_and_rename',
		'test_odoowoo_mistake_dashboard_name_shown_via_tag',
		'test_odoowoo_mistake_unknown_dashboard_shows_not_found_not_empty_forms',
		'test_odoowoo_mistake_unknown_tab_falls_back_to_first',
		'test_odoowoo_mistake_limit_enforced_by_django_not_the_ui',
		'test_odoowoo_mistake_webhook_secret_never_in_reply',
		'test_odoowoo_mistake_error_details_never_shown',
	);

	/**
	 * Every definition of a test with this name, with its body: PHP methods end at the next line
	 * that is a lone tab-indented closing brace, node tests at the next top-level test( or the end.
	 *
	 * @param string $name Test name.
	 * @return array<string, string> File => body.
	 */
	private function definitions( string $name ): array {
		$found = array();
		$dir   = dirname( __DIR__ ) . '/tests';

		foreach ( (array) glob( $dir . '/*Test.php' ) as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match( '/function ' . preg_quote( $name, '/' ) . '\s*\(.*?\n\t\}\n/s', $source, $match ) ) {
				$found[ basename( $file ) ] = $match[0];
			}
		}

		foreach ( (array) glob( $dir . '/js/*.mjs' ) as $file ) {
			$source = (string) file_get_contents( $file );

			if ( preg_match( '/^test\(\s*\'' . preg_quote( $name, '/' ) . '\'.*?(?=^test\(|\z)/ms', $source, $match ) ) {
				$found[ 'js/' . basename( $file ) ] = $match[0];
			}
		}

		return $found;
	}

	public function test_every_spec_mistake_has_a_named_test(): void {
		foreach ( self::MISTAKES as $name ) {
			$definitions = $this->definitions( $name );

			$this->assertNotSame( array(), $definitions, "$name has no test" );

			foreach ( $definitions as $file => $body ) {
				$this->assertMatchesRegularExpression( '/assert(?:[A-Z]\w*)?\s*[(.]|assert\.\w+\(/', $body, "$name in $file asserts nothing" );
			}
		}
	}

	public function test_the_finder_sees_an_empty_body_and_a_missing_name(): void {
		// The scan can fail: a name that is nowhere gives no definition, and the assertion pattern needs a call.
		$this->assertSame( array(), $this->definitions( 'test_odoowoo_mistake_nobody_wrote_this' ) );

		$pattern = '/assert(?:[A-Z]\w*)?\s*[(.]|assert\.\w+\(/';

		$this->assertSame( 0, preg_match( $pattern, "function x() {\n\t\t// no assertion here\n\t}\n" ) );
		$this->assertSame( 1, preg_match( $pattern, "function x() {\n\t\t\$this->assertSame( 1, 1 );\n\t}\n" ) );
		$this->assertSame( 1, preg_match( $pattern, "test( 'x', () => {\n\tassert.equal( 1, 1 );\n} );\n" ) );
	}
}
