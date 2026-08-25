<?php
declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BuildScriptTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/fx-build-' . uniqid();
		mkdir( $this->dir . '/pkg', 0755, true );
		file_put_contents(
			$this->dir . '/pkg/fx-demo.php',
			"<?php\n/**\n * Plugin Name: FX Demo\n * Version: 0.0.0\n * Update URI: https://wp-updates.fanxie.cloud/demo/plugin-fx-demo\n */\n"
		);
		file_put_contents( $this->dir . '/pkg/readme.txt', "Stable tag: 0.0.0\n" );
		file_put_contents( $this->dir . '/pkg/.DS_Store', 'junk' );
		file_put_contents( $this->dir . '/pkg/phpcs.xml.dist', '<ruleset/>' );
		file_put_contents(
			$this->dir . '/Key.php',
			"<?php\nfinal class Key {\n\tpublic const COMPILED = 'QQ==';\n}\n"
		);
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tearDown();
	}

	private function build( string $extra = '' ): array {
		$cmd = 'bash ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/build.sh' )
			. ' --source=' . escapeshellarg( $this->dir . '/pkg' )
			. ' --slug=fx-demo --version=1.2.3 --main-file=fx-demo.php'
			. ' --key-file=' . escapeshellarg( $this->dir . '/Key.php' )
			. ' --out=' . escapeshellarg( $this->dir . '/dist' ) . ' ' . $extra . ' 2>&1';
		exec( $cmd, $output, $code );
		return array( $output, $code );
	}

	public function test_builds_a_stamped_pruned_zip_with_one_top_level_directory(): void {
		[ $output, $code ] = $this->build();
		$this->assertSame( 0, $code, implode( "\n", $output ) );

		$zip = end( $output );
		$this->assertStringEndsWith( 'fx-demo-1.2.3.zip', (string) $zip );
		$this->assertFileExists( (string) $zip );

		exec( 'unzip -Z1 ' . escapeshellarg( (string) $zip ), $entries );
		$roots = array_unique( array_map( static fn( $e ) => explode( '/', $e )[0], $entries ) );
		$this->assertSame( array( 'fx-demo' ), array_values( $roots ) );
		$this->assertNotContains( 'fx-demo/.DS_Store', $entries, 'Dev junk is pruned.' );
		$this->assertNotContains( 'fx-demo/phpcs.xml.dist', $entries, '*.dist files are pruned.' );

		exec( 'unzip -p ' . escapeshellarg( (string) $zip ) . ' fx-demo/fx-demo.php', $main );
		$this->assertContains( ' * Version: 1.2.3', $main, 'The version from the tag is stamped into the header.' );
		exec( 'unzip -p ' . escapeshellarg( (string) $zip ) . ' fx-demo/readme.txt', $readme );
		$this->assertContains( 'Stable tag: 1.2.3', $readme );
	}

	public function test_refuses_to_build_when_the_compiled_key_is_empty(): void {
		file_put_contents( $this->dir . '/Key.php', "<?php\nfinal class Key {\n\tpublic const COMPILED = '';\n}\n" );

		[ $output, $code ] = $this->build();
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'no update signing key', strtolower( implode( "\n", $output ) ) );

		[ , $code ] = $this->build( '--allow-unkeyed' );
		$this->assertSame( 0, $code, 'Local inspection builds are allowed with the explicit flag.' );
	}

	public function test_refuses_to_build_when_the_key_file_is_missing(): void {
		unlink( $this->dir . '/Key.php' );

		[ $output, $code ] = $this->build();
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'key file not found', strtolower( implode( "\n", $output ) ) );

		// A missing file is an error, not an unkeyed build — --allow-unkeyed must not bypass it.
		[ $output, $code ] = $this->build( '--allow-unkeyed' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'key file not found', strtolower( implode( "\n", $output ) ) );
	}

	public function test_refuses_to_build_when_the_key_file_has_no_compiled_constant(): void {
		file_put_contents( $this->dir . '/Key.php', "<?php\nfinal class Key {\n\tpublic const OTHER = 'x';\n}\n" );

		[ $output, $code ] = $this->build();
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'no compiled constant', strtolower( implode( "\n", $output ) ) );

		// A missing constant is an error, not an unkeyed build — --allow-unkeyed must not bypass it.
		[ $output, $code ] = $this->build( '--allow-unkeyed' );
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'no compiled constant', strtolower( implode( "\n", $output ) ) );
	}
}
