<?php
declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WpUpdates\Log;
use FanxieLab\WpUpdates\ManifestSource;
use FanxieLab\WpUpdates\Package;
use FanxieLab\WpUpdates\PackageType;
use FanxieLab\WpUpdates\UpdateConfig;
use FanxieLab\WpUpdates\Verifier;
use PHPUnit\Framework\TestCase;

final class ManifestSourceTest extends TestCase {

	private string $public = '';
	private string $secret = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$pair         = sodium_crypto_sign_keypair();
		$this->public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
		$this->secret = sodium_crypto_sign_secretkey( $pair );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function config(): UpdateConfig {
		return new UpdateConfig(
			host: 'wp-updates.fanxie.cloud',
			namespace: 'demo',
			public_key: $this->public,
			hook_prefix: 'fxdemo',
			packages: array( new Package( PackageType::Plugin, 'fx-demo', 'fx-demo/fx-demo.php' ) ),
		);
	}

	private function source(): ManifestSource {
		$config = $this->config();
		return new ManifestSource( $config, new Verifier( $this->public, $config->host, $config->namespace ), new Log( 'fxdemo' ) );
	}

	private function envelope( string $version = '9.0.0' ): string {
		$payload = json_encode(
			array(
				'type'    => 'plugin',
				'slug'    => 'fx-demo',
				'version' => $version,
				'package' => 'https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-' . $version . '.zip',
				'url'     => '',
			),
			JSON_UNESCAPED_SLASHES
		);
		return (string) json_encode(
			array(
				'schema'    => Verifier::SCHEMA,
				'payload'   => base64_encode( (string) $payload ),
				'signature' => base64_encode( sodium_crypto_sign_detached( (string) $payload, $this->secret ) ),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	public function test_transient_key_is_per_package(): void {
		$this->assertSame(
			'fxdemo_upd_plugin_fx-demo',
			ManifestSource::transient_key( 'fxdemo', new Package( PackageType::Plugin, 'fx-demo', 'fx-demo/fx-demo.php' ) )
		);
	}

	public function test_a_verified_fetch_caches_the_envelope_body_not_the_conclusion(): void {
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_site_transient' )->justReturn( false );
		$stored = array();
		Functions\when( 'set_site_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$stored ) {
				$stored[] = array( $key, $value, $ttl );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => $this->envelope(), 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://site.example/' );

		$manifest = $this->source()->manifest_for( $this->config()->packages[0] );

		$this->assertSame( '9.0.0', $manifest?->version );
		$this->assertSame( 'fxdemo_upd_plugin_fx-demo', $stored[0][0] );
		$this->assertSame( array( 'body' => $this->envelope() ), $stored[0][1], 'The raw envelope is cached, not the parsed manifest.' );
		$this->assertSame( ManifestSource::SUCCESS_TTL, $stored[0][2] );
	}

	public function test_a_cached_envelope_is_reverified_on_read_and_a_tampered_one_is_negative_cached(): void {
		Functions\when( 'do_action' )->justReturn( null );
		$tampered = str_replace( '"schema"', '"schama"', $this->envelope() ); // any byte change breaks parse/verify
		Functions\when( 'get_site_transient' )->justReturn( array( 'body' => $tampered ) );
		$stored = array();
		Functions\when( 'set_site_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$stored ) {
				$stored[] = array( $key, $value, $ttl );
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->assertNull( $this->source()->manifest_for( $this->config()->packages[0] ) );
		$this->assertSame( array( 'body' => null ), $stored[0][1], 'A poisoned cache is replaced by a negative entry, not re-fetched.' );
		$this->assertSame( ManifestSource::FAILURE_TTL, $stored[0][2] );
	}

	public function test_a_negative_cache_entry_suppresses_the_network(): void {
		Functions\when( 'get_site_transient' )->justReturn( array( 'body' => null ) );
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertNull( $this->source()->manifest_for( $this->config()->packages[0] ) );
	}
}
