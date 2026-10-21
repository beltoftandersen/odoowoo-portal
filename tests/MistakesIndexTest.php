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
	 * Does this PHP source define the method with a body that makes a real assertion? Found with
	 * the tokenizer: the body is brace-matched, comments and string literals are ignored, and a call
	 * to assert*() or expect*() is required (a one-line empty method has an empty body of its own).
	 *
	 * @param string $source PHP source.
	 * @param string $name   Method name.
	 * @return bool|null Null when the method is not defined.
	 */
	private function php_method_asserts( string $source, string $name ): ?bool {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}

			$j = $i + 1;

			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}

			if ( ! isset( $tokens[ $j ] ) || ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || $name !== $tokens[ $j ][1] ) {
				continue;
			}

			// The body starts at the first { after the signature (a signature has no braces).
			while ( $j < $count && '{' !== $tokens[ $j ] ) {
				++$j;
			}

			$depth = 0;

			for ( ; $j < $count; $j++ ) {
				$token = $tokens[ $j ];

				if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $token ) {
					--$depth;

					if ( 0 === $depth ) {
						return false;
					}
				} elseif ( is_array( $token ) && T_STRING === $token[0] && preg_match( '/^(assert|expect)/', $token[1] ) ) {
					$next = $j + 1;

					while ( isset( $tokens[ $next ] ) && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
						++$next;
					}

					if ( isset( $tokens[ $next ] ) && '(' === $tokens[ $next ] ) {
						return true;
					}
				}
			}

			return false;
		}

		return null;
	}

	/**
	 * Does this node test file define the test with a body that calls assert? Comments and quoted
	 * text are removed first; the body runs to the next top-level test( or the end.
	 *
	 * @param string $source JavaScript source.
	 * @param string $name   Test name.
	 * @return bool|null Null when it is not defined.
	 */
	private function js_test_asserts( string $source, string $name ): ?bool {
		if ( ! preg_match( '/^test\(\s*\'' . preg_quote( $name, '/' ) . '\'.*?(?=^test\(|\z)/ms', $source, $match ) ) {
			return null;
		}

		$body = (string) preg_replace( array( '#/\*.*?\*/#s', '#//[^\n]*#', '/\'(?:[^\'\\\\\n]|\\\\.)*\'/', '/"(?:[^"\\\\\n]|\\\\.)*"/', '/`(?:[^`\\\\]|\\\\.)*`/s' ), '', substr( $match[0], strlen( "test( '" . $name . "'" ) ) );

		return 1 === preg_match( '/\bassert(?:\.\w+)?\s*\(/', $body );
	}

	/**
	 * Every definition of a test with this name: file => whether its body asserts.
	 *
	 * @param string $name Test name.
	 * @param string $dir  The tests folder.
	 * @return array<string, bool>
	 */
	private function definitions( string $name, string $dir = '' ): array {
		$found = array();
		$dir   = '' === $dir ? __DIR__ : $dir;

		foreach ( (array) glob( $dir . '/*Test.php' ) as $file ) {
			$result = $this->php_method_asserts( (string) file_get_contents( $file ), $name );

			if ( null !== $result ) {
				$found[ basename( $file ) ] = $result;
			}
		}

		foreach ( (array) glob( $dir . '/js/*.mjs' ) as $file ) {
			$result = $this->js_test_asserts( (string) file_get_contents( $file ), $name );

			if ( null !== $result ) {
				$found[ 'js/' . basename( $file ) ] = $result;
			}
		}

		return $found;
	}

	public function test_every_spec_mistake_has_a_named_test(): void {
		foreach ( self::MISTAKES as $name ) {
			$definitions = $this->definitions( $name );

			$this->assertNotSame( array(), $definitions, "$name has no test" );

			foreach ( $definitions as $file => $asserts ) {
				$this->assertTrue( $asserts, "$name in $file asserts nothing" );
			}
		}
	}

	public function test_the_finder_only_passes_a_real_assertion(): void {
		$dir = sys_get_temp_dir() . '/odoowoo-mistakes-' . wp_generate_password( 8, false );
		mkdir( $dir . '/js', 0777, true );

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$dir . '/FakeTest.php',
			"<?php\nclass FakeTest {\n"
			. "\tpublic function test_a_empty(): void {}\n"
			. "\tpublic function test_b_commented(): void {\n\t\t// \$this->assertSame( 1, 1 );\n\t\t\$x = 'assertTrue( 1 )';\n\t}\n"
			. "\tpublic function test_c_real(): void {\n\t\tif ( true ) {\n\t\t\t\$this->assertSame( 1, 1 );\n\t\t}\n\t}\n"
			. "\tpublic function test_d_after(): void {\n\t\t\$this->assertTrue( true );\n\t}\n}\n"
		);
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$dir . '/js/fake.test.mjs',
			"test( 'test_e_js_comment', () => {\n\t// assert.equal( 1, 1 );\n\tconst s = 'assert.ok( 1 )';\n} );\n\ntest( 'test_f_js_real', () => {\n\tassert.equal( 1, 1 );\n} );\n"
		);

		$this->assertSame( array( 'FakeTest.php' => false ), $this->definitions( 'test_a_empty', $dir ), 'A one-line empty method does not borrow the next one.' );
		$this->assertSame( array( 'FakeTest.php' => false ), $this->definitions( 'test_b_commented', $dir ) );
		$this->assertSame( array( 'FakeTest.php' => true ), $this->definitions( 'test_c_real', $dir ) );
		$this->assertSame( array( 'FakeTest.php' => true ), $this->definitions( 'test_d_after', $dir ) );
		$this->assertSame( array( 'js/fake.test.mjs' => false ), $this->definitions( 'test_e_js_comment', $dir ) );
		$this->assertSame( array( 'js/fake.test.mjs' => true ), $this->definitions( 'test_f_js_real', $dir ) );
		$this->assertSame( array(), $this->definitions( 'test_odoowoo_mistake_nobody_wrote_this', $dir ) );

		array_map( 'unlink', (array) glob( $dir . '/js/*' ) );
		array_map( 'unlink', (array) glob( $dir . '/*.php' ) );
		rmdir( $dir . '/js' );
		rmdir( $dir );
	}
}
