<?php
/**
 * Unit tests for the hook names the update client attaches to.
 *
 * @package FanxieLab\WpUpdates\Tests
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Unit;

use FanxieLab\WpUpdates\PackageType;
use PHPUnit\Framework\TestCase;

/**
 * Pins the filter names down.
 *
 * These strings are the whole integration surface with WordPress core. A typo
 * in one of them produces a client that runs, registers, passes every other
 * test, and is never called. Asserting the literal names is the only way that
 * mistake fails loudly, so the expected values below are written out rather
 * than derived from the code under test.
 *
 * Checked against WordPress 7.1, `wp-includes/update.php` lines 555 and 833.
 */
final class PackageTypeTest extends TestCase {

	/**
	 * The host-scoped offer filters carry the hostname from the `Update URI` header.
	 *
	 * @return void
	 */
	public function test_offer_filter_names(): void {
		$this->assertSame(
			'update_themes_wp-updates.fanxie.cloud',
			PackageType::Theme->offer_filter( 'wp-updates.fanxie.cloud' )
		);
		$this->assertSame(
			'update_plugins_wp-updates.fanxie.cloud',
			PackageType::Plugin->offer_filter( 'wp-updates.fanxie.cloud' )
		);
	}

	/**
	 * The auto-update filters are `auto_update_{$type}`, not `auto_update_{$type}s`.
	 *
	 * @return void
	 */
	public function test_auto_update_filter_names(): void {
		$this->assertSame( 'auto_update_theme', PackageType::Theme->auto_update_filter() );
		$this->assertSame( 'auto_update_plugin', PackageType::Plugin->auto_update_filter() );
	}

	/**
	 * `WP_Automatic_Updater` reads `$item->theme` and `$item->plugin`.
	 *
	 * @return void
	 */
	public function test_identity_fields(): void {
		$this->assertSame( 'theme', PackageType::Theme->identity_field() );
		$this->assertSame( 'plugin', PackageType::Plugin->identity_field() );
	}

	/**
	 * The enum's backing values are the strings core uses for `$type`.
	 *
	 * @return void
	 */
	public function test_backing_values_match_cores_type_strings(): void {
		$this->assertSame( PackageType::Theme, PackageType::tryFrom( 'theme' ) );
		$this->assertSame( PackageType::Plugin, PackageType::tryFrom( 'plugin' ) );
		$this->assertNull( PackageType::tryFrom( 'mu-plugin' ) );
	}
}
