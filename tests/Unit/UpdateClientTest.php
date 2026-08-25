<?php
declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WpUpdates\Log;
use FanxieLab\WpUpdates\ManifestSource;
use FanxieLab\WpUpdates\Package;
use FanxieLab\WpUpdates\PackageType;
use FanxieLab\WpUpdates\UpdateClient;
use FanxieLab\WpUpdates\UpdateConfig;
use FanxieLab\WpUpdates\Verifier;
use PHPUnit\Framework\TestCase;

final class UpdateClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
	}

	protected function tearDown(): void {
		UpdateClient::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function config( string $ns = 'demo', string $prefix = 'fxdemo' ): UpdateConfig {
		return new UpdateConfig(
			host: 'wp-updates.fanxie.cloud',
			namespace: $ns,
			public_key: 'irrelevant-here',
			hook_prefix: $prefix,
			packages: array( new Package( PackageType::Plugin, 'fx-demo', 'fx-demo/fx-demo.php' ) ),
		);
	}

	/** A source whose manifest_for is never reached (fetch would explode the test). */
	private function dead_source( UpdateConfig $config ): ManifestSource {
		return new ManifestSource( $config, new Verifier( '', $config->host, $config->namespace ), new Log( $config->hook_prefix ) );
	}

	public function test_a_foreign_update_uri_passes_the_incoming_value_through(): void {
		UpdateClient::register( $this->config(), $this->dead_source( $this->config() ) );

		$marker = array( 'version' => '9.9.9', 'package' => 'https://elsewhere.example/x.zip' );
		$result = UpdateClient::on_plugin_update(
			$marker,
			array( 'UpdateURI' => 'https://wp-updates.fanxie.cloud/stilotex/plugin-other', 'Version' => '1.0.0' ),
			'other/other.php'
		);

		$this->assertSame( $marker, $result, 'An unowned URI is left for whichever sibling owns it.' );
	}

	public function test_an_owned_uri_that_cannot_verify_returns_false_even_over_a_previous_offer(): void {
		Functions\when( 'get_site_transient' )->justReturn( array( 'body' => null ) ); // negative cache: no fetch
		UpdateClient::register( $this->config(), $this->dead_source( $this->config() ) );

		$result = UpdateClient::on_plugin_update(
			array( 'version' => '9.9.9' ),
			array( 'UpdateURI' => 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo', 'Version' => '1.0.0' ),
			'fx-demo/fx-demo.php'
		);

		$this->assertFalse( $result, 'Owned packages fail closed; a stranger\'s value never stands in for ours.' );
	}

	public function test_an_owned_uri_on_the_wrong_identity_is_refused(): void {
		UpdateClient::register( $this->config(), $this->dead_source( $this->config() ) );

		$result = UpdateClient::on_plugin_update(
			false,
			array( 'UpdateURI' => 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo', 'Version' => '1.0.0' ),
			'impostor/impostor.php'
		);

		$this->assertFalse( $result );
	}

	public function test_auto_update_is_scoped_to_owned_offer_ids_and_matching_identity(): void {
		UpdateClient::register( $this->config(), $this->dead_source( $this->config() ) );

		$ours = (object) array( 'id' => 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo', 'plugin' => 'fx-demo/fx-demo.php' );
		$this->assertTrue( UpdateClient::on_auto_update( null, $ours ) );

		$wrong_identity = (object) array( 'id' => 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo', 'plugin' => 'impostor/impostor.php' );
		$this->assertNull( UpdateClient::on_auto_update( null, $wrong_identity ) );

		$foreign = (object) array( 'id' => 'https://wordpress.org/plugins/akismet', 'plugin' => 'akismet/akismet.php' );
		$this->assertNull( UpdateClient::on_auto_update( null, $foreign ), 'null passes through: core reads it as "unhooked".' );
		$this->assertFalse( UpdateClient::on_auto_update( false, $foreign ) );
	}

	public function test_registering_twice_returns_the_first_instance_unless_a_source_is_injected(): void {
		$config = $this->config();
		$first  = UpdateClient::register( $config, $this->dead_source( $config ) );

		$this->assertSame( $first, UpdateClient::register( $config ) );
		$this->assertNotSame( $first, UpdateClient::register( $config, $this->dead_source( $config ) ), 'An injected source is an override and must behave like one.' );
	}

	public function test_two_namespaces_coexist_and_each_answers_only_its_own(): void {
		Functions\when( 'get_site_transient' )->justReturn( array( 'body' => null ) );
		UpdateClient::register( $this->config(), $this->dead_source( $this->config() ) );
		$other = $this->config( 'demo2', 'fxdemo2' );
		UpdateClient::register( $other, $this->dead_source( $other ) );

		// demo2's URI reaches demo2's client (which fails closed here — negative cache), not demo's pass-through.
		$result = UpdateClient::on_plugin_update(
			array( 'version' => '9.9.9' ),
			array( 'UpdateURI' => 'https://wp-updates.fanxie.cloud/demo2/plugin-fx-demo', 'Version' => '1.0.0' ),
			'fx-demo/fx-demo.php'
		);
		$this->assertFalse( $result );
	}
}
