<?php
declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseToolTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/fx-release-' . uniqid();
		mkdir( $this->dir, 0755, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		parent::tearDown();
	}

	/** @param array<string, string> $env */
	private function tool( string $args, array $env = array() ): array {
		$exports = '';
		foreach ( $env as $name => $value ) {
			$exports .= $name . '=' . escapeshellarg( $value ) . ' ';
		}
		exec( $exports . 'php ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/release.php' ) . ' ' . $args . ' 2>&1', $output, $code );
		return array( implode( "\n", $output ), $code );
	}

	public function test_keygen_writes_the_public_key_into_a_compiled_constant(): void {
		$file = $this->dir . '/Key.php';
		file_put_contents( $file, "<?php\nfinal class Key {\n\tpublic const COMPILED = '';\n}\n" );

		[ $out, $code ] = $this->tool( 'keygen --write-to=' . escapeshellarg( $file ) );

		$this->assertSame( 0, $code, $out );
		$this->assertMatchesRegularExpression( "/public const COMPILED = '[A-Za-z0-9+\\/]+={0,2}';/", (string) file_get_contents( $file ) );
		$this->assertStringContainsString( 'Secret key', $out );
	}

	public function test_manifest_then_verify_round_trip_with_the_matching_key(): void {
		$file = $this->dir . '/Key.php';
		file_put_contents( $file, "<?php\nfinal class Key {\n\tpublic const COMPILED = '';\n}\n" );
		[ $out ] = $this->tool( 'keygen --write-to=' . escapeshellarg( $file ) );
		preg_match( '/^\s+([A-Za-z0-9+\/]+={0,2})\s*$/m', substr( $out, (int) strpos( $out, 'Secret key' ) ), $m );
		$secret = $m[1];

		$manifest = $this->dir . '/plugin-fx-demo.json';
		[ $out, $code ] = $this->tool(
			'manifest --type=plugin --slug=fx-demo --version=1.2.3'
			. ' --package=https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-1.2.3.zip'
			. ' --key-env=TEST_SIGNING_KEY --out=' . escapeshellarg( $manifest ),
			array( 'TEST_SIGNING_KEY' => $secret )
		);
		$this->assertSame( 0, $code, $out );

		[ $out, $code ] = $this->tool(
			'verify --manifest=' . escapeshellarg( $manifest )
			. ' --host=wp-updates.fanxie.cloud --namespace=demo --key-file=' . escapeshellarg( $file )
		);
		$this->assertSame( 0, $code, $out );
		$this->assertStringContainsString( 'Manifest verifies: plugin fx-demo 1.2.3', $out );
	}

	public function test_verify_rejects_a_manifest_signed_by_a_different_key(): void {
		$signer = $this->dir . '/Signer.php';
		file_put_contents( $signer, "<?php\nfinal class S {\n\tpublic const COMPILED = '';\n}\n" );
		[ $out ] = $this->tool( 'keygen --write-to=' . escapeshellarg( $signer ) );
		preg_match( '/^\s+([A-Za-z0-9+\/]+={0,2})\s*$/m', substr( $out, (int) strpos( $out, 'Secret key' ) ), $m );

		$manifest = $this->dir . '/plugin-fx-demo.json';
		$this->tool(
			'manifest --type=plugin --slug=fx-demo --version=1.2.3'
			. ' --package=https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-1.2.3.zip'
			. ' --key-env=TEST_SIGNING_KEY --out=' . escapeshellarg( $manifest ),
			array( 'TEST_SIGNING_KEY' => $m[1] )
		);

		$other = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
		[ $out, $code ] = $this->tool(
			'verify --manifest=' . escapeshellarg( $manifest )
			. ' --host=wp-updates.fanxie.cloud --namespace=demo --key=' . escapeshellarg( $other )
		);
		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'manifest rejected', $out );
	}

	public function test_zip_produces_a_single_top_level_directory_named_for_the_slug(): void {
		mkdir( $this->dir . '/pkg/sub', 0755, true );
		file_put_contents( $this->dir . '/pkg/a.php', '<?php' );
		file_put_contents( $this->dir . '/pkg/sub/b.php', '<?php' );

		$zip = $this->dir . '/fx-demo-1.0.0.zip';
		[ $out, $code ] = $this->tool( 'zip --source=' . escapeshellarg( $this->dir . '/pkg' ) . ' --slug=fx-demo --out=' . escapeshellarg( $zip ) );
		$this->assertSame( 0, $code, $out );

		exec( 'unzip -Z1 ' . escapeshellarg( $zip ) . ' | cut -d/ -f1 | sort -u', $roots );
		$this->assertSame( array( 'fx-demo' ), $roots );
	}
}
