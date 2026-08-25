<?php
declare( strict_types=1 );

namespace Fanxie\WpUpdates\Tests\Unit;

use Fanxie\WpUpdates\Package;
use Fanxie\WpUpdates\PackageType;
use Fanxie\WpUpdates\UpdateConfig;
use PHPUnit\Framework\TestCase;

final class UpdateConfigTest extends TestCase {

	private function config(): UpdateConfig {
		return new UpdateConfig(
			host: 'wp-updates.fanxie.cloud',
			namespace: 'demo',
			public_key: 'compiled-key-base64',
			hook_prefix: 'fxdemo',
			packages: array(
				new Package( PackageType::Theme, 'fx-demo-theme', 'fx-demo-theme' ),
				new Package( PackageType::Plugin, 'fx-demo', 'fx-demo/fx-demo.php' ),
			),
		);
	}

	public function test_update_uri_and_manifest_url_follow_the_path_scheme(): void {
		$config = $this->config();
		$theme  = $config->packages[0];

		$this->assertSame( 'https://wp-updates.fanxie.cloud/demo/theme-fx-demo-theme', $config->update_uri( $theme ) );
		$this->assertSame( 'https://wp-updates.fanxie.cloud/demo/theme-fx-demo-theme.json', $config->manifest_url( $theme ) );
	}

	public function test_ownership_is_the_full_path_not_the_host(): void {
		$config = $this->config();

		$this->assertSame( 'fx-demo', $config->package_for_update_uri( 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo' )?->slug );
		// A trailing .json in the header is tolerated: the header is an identifier.
		$this->assertSame( 'fx-demo', $config->package_for_update_uri( 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo.json' )?->slug );
		// Same host, different namespace: NOT ours.
		$this->assertNull( $config->package_for_update_uri( 'https://wp-updates.fanxie.cloud/stilotex/plugin-fx-demo' ) );
		// Same path, different host: NOT ours.
		$this->assertNull( $config->package_for_update_uri( 'https://evil.example/demo/plugin-fx-demo' ) );
		// Unknown slug in our namespace: NOT ours.
		$this->assertNull( $config->package_for_update_uri( 'https://wp-updates.fanxie.cloud/demo/plugin-other' ) );
		// Type participates in the path: a theme URI does not resolve to the plugin.
		$this->assertNull( $config->package_for_update_uri( 'https://wp-updates.fanxie.cloud/demo/theme-fx-demo' ) );
	}

	public function test_override_constant_is_derived_from_the_hook_prefix(): void {
		$this->assertSame( 'FXDEMO_UPDATE_PUBLIC_KEY', $this->config()->override_constant() );

		$dashed = new UpdateConfig( 'h.example', 'ns', 'k', 'my-client', array() );
		$this->assertSame( 'MY_CLIENT_UPDATE_PUBLIC_KEY', $dashed->override_constant() );
	}

	public function test_resolve_public_key_prefers_a_defined_override_constant(): void {
		$config = new UpdateConfig( 'h.example', 'ns', 'compiled', 'fxcfg' . uniqid(), array() );

		$this->assertSame( 'compiled', $config->resolve_public_key() );

		define( $config->override_constant(), '  staging-key  ' );
		$this->assertSame( 'staging-key', $config->resolve_public_key() );
	}

	public function test_refused_action_name(): void {
		$this->assertSame( 'fxdemo_update_refused', $this->config()->refused_action() );
	}
}
