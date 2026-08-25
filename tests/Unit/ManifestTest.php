<?php
/**
 * Unit tests for manifest parsing and version comparison.
 *
 * @package FanxieLab\WpUpdates\Tests
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Unit;

use FanxieLab\WpUpdates\Manifest;
use FanxieLab\WpUpdates\ManifestError;
use FanxieLab\WpUpdates\PackageType;
use PHPUnit\Framework\TestCase;

/**
 * Exercises Manifest without WordPress and without a signature.
 *
 * Manifest is the last line of defence between a payload that verified and the
 * WordPress upgrader. Everything it refuses here is something a signed-but-wrong
 * manifest could otherwise talk core into doing.
 */
final class ManifestTest extends TestCase {

	/**
	 * The update host every test in this file trusts.
	 */
	private const HOST = 'wp-updates.fanxie.cloud';

	/**
	 * The tenant namespace every test in this file trusts.
	 */
	private const TENANT = 'demo';

	/**
	 * A payload with every field populated and plausible.
	 *
	 * Defaults to the fx-demo plugin at version 1.4.2, with a package URL that
	 * lives inside this namespace and agrees with the type/slug/version above.
	 *
	 * @param array<string, mixed> $overrides Fields to replace or add.
	 * @return array<string, mixed>
	 */
	private function payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'         => 'plugin',
				'slug'         => 'fx-demo',
				'version'      => '1.4.2',
				'package'      => 'https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-1.4.2.zip',
				'url'          => 'https://wp-updates.fanxie.cloud/demo/fx-demo',
				'requires'     => '6.6',
				'requires_php' => '8.4',
				'tested'       => '7.1',
			),
			$overrides
		);
	}

	/**
	 * A well-formed payload parses into the value object.
	 *
	 * @return void
	 */
	public function test_a_complete_payload_parses(): void {
		$manifest = Manifest::from_array(
			$this->payload(
				array(
					'type'    => 'theme',
					'slug'    => 'fx-demo-theme',
					'version' => '1.2.3',
					'package' => 'https://wp-updates.fanxie.cloud/demo/packages/theme-fx-demo-theme-1.2.3.zip',
				)
			),
			self::HOST,
			self::TENANT
		);

		$this->assertSame( PackageType::Theme, $manifest->type );
		$this->assertSame( 'fx-demo-theme', $manifest->slug );
		$this->assertSame( '1.2.3', $manifest->version );
		$this->assertSame( '6.6', $manifest->requires );
		$this->assertSame( '8.4', $manifest->requires_php );
		$this->assertSame( '7.1', $manifest->tested );
	}

	/**
	 * Optional descriptive fields may be absent.
	 *
	 * @return void
	 */
	public function test_optional_fields_default_to_empty_strings(): void {
		$manifest = Manifest::from_array(
			array(
				'type'    => 'plugin',
				'slug'    => 'fx-demo',
				'version' => '0.1.0',
				'package' => 'https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-0.1.0.zip',
			),
			self::HOST,
			self::TENANT
		);

		$this->assertSame( '', $manifest->url );
		$this->assertSame( '', $manifest->requires );
		$this->assertSame( '', $manifest->tested );
	}

	/**
	 * Anything that is not a JSON object is refused outright.
	 *
	 * @return void
	 */
	public function test_a_non_object_payload_is_refused(): void {
		$this->expectException( ManifestError::class );

		Manifest::from_array( 'not-an-object', self::HOST, self::TENANT );
	}

	/**
	 * A package type we do not publish is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_type_is_refused(): void {
		$this->expectException( ManifestError::class );

		Manifest::from_array( $this->payload( array( 'type' => 'mu-plugin' ) ), self::HOST, self::TENANT );
	}

	/**
	 * Versions that `version_compare()` would silently reinterpret are refused.
	 *
	 * @dataProvider provide_unusable_versions
	 *
	 * @param mixed $version The version string under test.
	 * @return void
	 */
	public function test_unusable_versions_are_refused( mixed $version ): void {
		$this->expectException( ManifestError::class );

		Manifest::from_array( $this->payload( array( 'version' => $version ) ), self::HOST, self::TENANT );
	}

	/**
	 * Version strings a manifest may not carry.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function provide_unusable_versions(): array {
		return array(
			'empty'         => array( '' ),
			'two segments'  => array( '1.2' ),
			'four segments' => array( '1.2.3.4' ),
			'leading v'     => array( 'v1.2.3' ),
			'words'         => array( 'latest' ),
			'not a string'  => array( 123 ),
			'trailing junk' => array( '1.2.3 (build 7)' ),
		);
	}

	/**
	 * Pre-release and build metadata are legitimate semver and are accepted.
	 *
	 * @return void
	 */
	public function test_a_prerelease_version_is_accepted(): void {
		$manifest = Manifest::from_array(
			$this->payload(
				array(
					'version' => '1.4.2-rc.1',
					'package' => 'https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-1.4.2-rc.1.zip',
				)
			),
			self::HOST,
			self::TENANT
		);

		$this->assertSame( '1.4.2-rc.1', $manifest->version );
	}

	/**
	 * A slug must look like a directory name, because that is what it becomes.
	 *
	 * @dataProvider provide_unusable_slugs
	 *
	 * @param mixed $slug The slug under test.
	 * @return void
	 */
	public function test_unusable_slugs_are_refused( mixed $slug ): void {
		$this->expectException( ManifestError::class );

		Manifest::from_array( $this->payload( array( 'slug' => $slug ) ), self::HOST, self::TENANT );
	}

	/**
	 * Slugs a manifest may not carry.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function provide_unusable_slugs(): array {
		return array(
			'empty'        => array( '' ),
			'traversal'    => array( '../evil' ),
			'slash'        => array( 'fx/demo' ),
			'uppercase'    => array( 'FxDemo' ),
			'single char'  => array( 'd' ),
			'not a string' => array( array( 'fx-demo' ) ),
		);
	}

	/**
	 * The upgrader may only be pointed at HTTPS.
	 *
	 * @return void
	 */
	public function test_a_plain_http_package_is_refused(): void {
		$this->expectException( ManifestError::class );

		Manifest::from_array(
			$this->payload( array( 'package' => 'http://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-1.4.2.zip' ) ),
			self::HOST,
			self::TENANT
		);
	}

	/**
	 * A package on an origin we do not publish from is refused.
	 *
	 * @return void
	 */
	public function test_a_package_on_an_unexpected_host_is_refused(): void {
		$this->expectException( ManifestError::class );

		Manifest::from_array(
			$this->payload( array( 'package' => 'https://evil.example/demo/packages/plugin-fx-demo-1.4.2.zip' ) ),
			self::HOST,
			self::TENANT
		);
	}

	/**
	 * Version comparison is `version_compare()`'s, and strictly greater-than.
	 *
	 * @dataProvider provide_version_comparisons
	 *
	 * @param string $offered   Version in the manifest.
	 * @param string $installed Version on the site.
	 * @param bool   $expected  Whether the manifest is newer.
	 * @return void
	 */
	public function test_version_comparison( string $offered, string $installed, bool $expected ): void {
		$manifest = Manifest::from_array(
			$this->payload(
				array(
					'version' => $offered,
					'package' => 'https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-' . $offered . '.zip',
				)
			),
			self::HOST,
			self::TENANT
		);

		$this->assertSame( $expected, $manifest->is_newer_than( $installed ) );
	}

	/**
	 * Comparisons that must hold, including the ones a naive string compare breaks on.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function provide_version_comparisons(): array {
		return array(
			'patch bump'               => array( '1.2.4', '1.2.3', true ),
			'minor bump'               => array( '1.3.0', '1.2.9', true ),
			'major bump'               => array( '2.0.0', '1.99.99', true ),
			'identical'                => array( '1.2.3', '1.2.3', false ),
			'older'                    => array( '1.2.2', '1.2.3', false ),
			'ten beats nine'           => array( '1.10.0', '1.9.0', true ),
			'release beats its rc'     => array( '1.2.3', '1.2.3-rc.1', true ),
			'rc does not beat release' => array( '1.2.3-rc.1', '1.2.3', false ),
			'zero-version site'        => array( '0.1.0', '0.0.1', true ),
		);
	}

	/**
	 * A theme offer carries `theme`; core never fills that in for us.
	 *
	 * @return void
	 */
	public function test_a_theme_offer_carries_the_stylesheet(): void {
		$offer = Manifest::from_array(
			$this->payload(
				array(
					'type'    => 'theme',
					'slug'    => 'fx-demo-theme',
					'version' => '1.2.3',
					'package' => 'https://wp-updates.fanxie.cloud/demo/packages/theme-fx-demo-theme-1.2.3.zip',
				)
			),
			self::HOST,
			self::TENANT
		)->to_offer( 'fx-demo-theme', 'https://wp-updates.fanxie.cloud/demo/fx-demo-theme' );

		$this->assertSame( 'fx-demo-theme', $offer['theme'] );
		$this->assertArrayNotHasKey( 'plugin', $offer );
		$this->assertSame( '1.2.3', $offer['version'] );
		$this->assertSame( '1.2.3', $offer['new_version'] );
		$this->assertSame( 'https://wp-updates.fanxie.cloud/demo/fx-demo-theme', $offer['id'] );
	}

	/**
	 * A plugin offer carries `plugin`, keyed by the plugin file.
	 *
	 * @return void
	 */
	public function test_a_plugin_offer_carries_the_plugin_file(): void {
		$offer = Manifest::from_array( $this->payload(), self::HOST, self::TENANT )
			->to_offer( 'fx-demo/fx-demo.php', 'https://wp-updates.fanxie.cloud/demo/fx-demo' );

		$this->assertSame( 'fx-demo/fx-demo.php', $offer['plugin'] );
		$this->assertArrayNotHasKey( 'theme', $offer );
		$this->assertSame( 'fx-demo', $offer['slug'] );
	}

	/**
	 * Absent optional fields are omitted rather than sent as empty strings.
	 *
	 * An empty `requires_php` would make `WP_Automatic_Updater` compare
	 * PHP_VERSION against '' and refuse the update.
	 *
	 * @return void
	 */
	public function test_empty_optional_fields_are_omitted_from_the_offer(): void {
		$offer = Manifest::from_array(
			array(
				'type'    => 'theme',
				'slug'    => 'fx-demo-theme',
				'version' => '1.2.3',
				'package' => 'https://wp-updates.fanxie.cloud/demo/packages/theme-fx-demo-theme-1.2.3.zip',
			),
			self::HOST,
			self::TENANT
		)->to_offer( 'fx-demo-theme', 'https://wp-updates.fanxie.cloud/demo/fx-demo-theme' );

		$this->assertArrayNotHasKey( 'requires_php', $offer );
		$this->assertArrayNotHasKey( 'requires', $offer );
		$this->assertArrayNotHasKey( 'tested', $offer );
	}

	/**
	 * A package URL that lives outside this tenant's namespace is refused, even
	 * on the correct host.
	 *
	 * @return void
	 */
	public function test_package_url_outside_the_namespace_is_refused(): void {
		$this->expectException( ManifestError::class );
		Manifest::from_array(
			$this->payload( array( 'package' => 'https://wp-updates.fanxie.cloud/stilotex/packages/plugin-fx-demo-1.4.2.zip' ) ),
			'wp-updates.fanxie.cloud',
			'demo'
		);
	}

	/**
	 * A package URL on a foreign host is refused, regardless of path.
	 *
	 * @return void
	 */
	public function test_package_url_on_a_foreign_host_is_refused(): void {
		$this->expectException( ManifestError::class );
		Manifest::from_array(
			$this->payload( array( 'package' => 'https://github.com/fanxielab/x/releases/download/v1/plugin-fx-demo-1.4.2.zip' ) ),
			'wp-updates.fanxie.cloud',
			'demo'
		);
	}

	/**
	 * The package filename must agree with the type, slug and version the
	 * payload claims — a signing key used carelessly still cannot smuggle a
	 * mismatched ZIP past this check.
	 *
	 * @return void
	 */
	public function test_package_filename_must_match_type_slug_and_version(): void {
		$this->expectException( ManifestError::class );
		Manifest::from_array(
			$this->payload( array( 'package' => 'https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-9.9.9.zip' ) ),
			'wp-updates.fanxie.cloud',
			'demo'
		); // payload says version 1.4.2; the zip name says 9.9.9.
	}
}
