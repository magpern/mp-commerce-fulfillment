<?php
/**
 * Static complement to the runtime PayloadGuard (spike S6): scans every
 * DomainEvent construction call site in src/ for a PII-shaped payload key,
 * independent of whether any test path actually exercises that call.
 *
 * @package MPCommerceFulfillment
 */

declare( strict_types=1 );

namespace MPCF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PII-shaped payload key guard.
 *
 * The runtime {@see \MPCF\Domain\Event\PayloadGuard}, wired into
 * `DomainEvent::for_fulfillment()`/`::global_event()`, already makes an
 * unsafe payload impossible to construct at all — this test is a second,
 * independent line of defense that does not depend on any code path
 * actually running: it finds every call site and inspects the literal
 * array passed as the payload argument directly, the same
 * scan-then-self-test pattern as the sibling structural guards
 * (DomainPurityGuardTest, DbConfinementGuardTest, WooConfinementGuardTest).
 */
final class PiiPayloadGuardTest extends TestCase {

	/**
	 * Same denylist as the runtime guard — kept independent (not a shared
	 * constant) deliberately, so a change to one guard's list does not
	 * silently narrow the other's coverage too.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_KEY_MARKERS = array(
		'email',
		'address',
		'phone',
		'ssn',
		'dob',
		'birth',
		'street',
		'city',
		'zip',
		'postal',
		'card_number',
		'cvv',
	);

	public function test_no_domain_event_call_site_declares_a_pii_shaped_payload_key(): void {
		$violations = $this->scan( dirname( __DIR__, 2 ) . '/src' );

		self::assertSame( array(), $violations );
	}

	public function test_the_scan_itself_catches_a_deliberately_planted_violation(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-pii-payload-fixture-' . uniqid();
		mkdir( $fixture_root, 0777, true );

		file_put_contents(
			$fixture_root . '/Tainted.php',
			"<?php\nnamespace MPCF\\Tainted;\nuse MPCF\\Domain\\Event\\DomainEvent;\nfinal class Tainted {\n\tpublic function build(): DomainEvent {\n\t\treturn DomainEvent::for_fulfillment( 1, 'x', \$actor, \$now, array( 'customer_email' => \$order->get_billing_email() ) );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertNotSame( array(), $violations, 'The scan must catch a PII-shaped payload key planted at a DomainEvent call site.' );
	}

	public function test_the_scan_does_not_flag_a_safe_call_site(): void {
		$fixture_root = sys_get_temp_dir() . '/mpcf-pii-payload-fixture-' . uniqid();
		mkdir( $fixture_root, 0777, true );

		file_put_contents(
			$fixture_root . '/Safe.php',
			"<?php\nnamespace MPCF\\Safe;\nuse MPCF\\Domain\\Event\\DomainEvent;\nfinal class Safe {\n\tpublic function build(): DomainEvent {\n\t\treturn DomainEvent::for_fulfillment( 1, 'x', \$actor, \$now, array( 'order_id' => \$order_id, 'from' => 'queued', 'to' => 'picking' ) );\n\t}\n}\n"
		);

		$violations = $this->scan( $fixture_root );

		$this->remove_directory( $fixture_root );

		self::assertSame( array(), $violations );
	}

	/**
	 * @return list<string>
	 */
	private function scan( string $src_root ): array {
		if ( ! is_dir( $src_root ) ) {
			return array();
		}

		$violations = array();
		$iterator   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src_root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( $this->call_site_arguments( $contents ) as $arguments ) {
				foreach ( $this->declared_array_keys( $arguments ) as $key ) {
					foreach ( self::FORBIDDEN_KEY_MARKERS as $marker ) {
						if ( false !== stripos( $key, $marker ) ) {
							$violations[] = $file->getPathname() . ": payload key \"{$key}\" matches forbidden marker \"{$marker}\"";
						}
					}
				}
			}
		}

		return $violations;
	}

	/**
	 * Every `for_fulfillment(`/`global_event(` call's full, paren-balanced
	 * argument list.
	 *
	 * @return list<string>
	 */
	private function call_site_arguments( string $contents ): array {
		$matches = array();
		preg_match_all( '/(?:for_fulfillment|global_event)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE );

		$arguments = array();

		foreach ( $matches[0] as $match ) {
			list( $text, $offset ) = $match;
			$open_paren_pos        = $offset + strlen( $text ) - 1;
			$arguments[]           = $this->balanced_parens( $contents, $open_paren_pos );
		}

		return $arguments;
	}

	/**
	 * The substring from an opening paren to its matching closing paren.
	 */
	private function balanced_parens( string $contents, int $open_paren_pos ): string {
		$depth  = 0;
		$length = strlen( $contents );

		for ( $i = $open_paren_pos; $i < $length; $i++ ) {
			if ( '(' === $contents[ $i ] ) {
				++$depth;
			}

			if ( ')' === $contents[ $i ] ) {
				--$depth;

				if ( 0 === $depth ) {
					return substr( $contents, $open_paren_pos, $i - $open_paren_pos + 1 );
				}
			}
		}

		return substr( $contents, $open_paren_pos );
	}

	/**
	 * Every string-literal array key declared in an argument-list fragment
	 * (`'key' =>` / `"key" =>`), wherever it appears — including nested
	 * arrays, which is exactly where a payload's own sub-arrays would put
	 * one.
	 *
	 * @return list<string>
	 */
	private function declared_array_keys( string $arguments ): array {
		preg_match_all( '/[\'"]([a-zA-Z0-9_]+)[\'"]\s*=>/', $arguments, $matches );

		return $matches[1];
	}

	private function remove_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $path );
	}
}
