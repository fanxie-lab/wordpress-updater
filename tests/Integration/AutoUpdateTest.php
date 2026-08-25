<?php
/**
 * Integration tests for the auto-update opt-in.
 *
 * @package FanxieLab\WpUpdates\Tests
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Integration;

use FanxieLab\WpUpdates\Tests\Support\SignedManifest;
use WP_UnitTestCase;

/**
 * `auto_update_theme` / `auto_update_plugin`, scoped to our two packages.
 *
 * The offers below are shaped the way `WP_Automatic_Updater::should_update()`
 * shapes them in WordPress 7.1: an object with `id` set from the `Update URI`
 * header, plus `theme` or `plugin` identifying the item. The `$update` argument
 * is `null` when nothing has hooked the filter — core uses that to tell
 * "undecided" from "decided false" — so a pass-through has to return `null`
 * unchanged rather than helpfully casting it to a boolean.
 *
 * `UpdateClient::auto_update()` is scoped twice over: `id` has to resolve to
 * one of the configured packages' canonical Update URI, *and* the offer's
 * identity field (`theme` or `plugin`) has to match that package's identity
 * exactly. Both checks have to pass for a `true`.
 */
final class AutoUpdateTest extends WP_UnitTestCase {

	use SignedManifest;

	/**
	 * The stylesheet our fixture theme is installed under.
	 */
	private const THEME_STYLESHEET = 'fx-demo-theme';

	/**
	 * The plugin file our fixture plugin is installed under.
	 */
	private const PLUGIN_FILE = 'fx-demo/fx-demo.php';

	/**
	 * The canonical Update URI for our theme — what core sets `id` from.
	 */
	private const THEME_ID = 'https://wp-updates.fanxie.cloud/demo/theme-fx-demo-theme';

	/**
	 * The canonical Update URI for our plugin — what core sets `id` from.
	 */
	private const PLUGIN_ID = 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo';

	/**
	 * Register the client with a real (if throwaway) key.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->start_update_harness();
		$this->register_client();
	}

	/**
	 * Unhook everything.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->stop_update_harness();
		parent::tear_down();
	}

	/**
	 * Build an update offer object the way core would hand it to the filter.
	 *
	 * @param array<string, string> $fields Offer fields.
	 * @return object
	 */
	private function offer( array $fields ): object {
		return (object) $fields;
	}

	/**
	 * Our theme updates itself.
	 *
	 * @return void
	 */
	public function test_our_theme_opts_in(): void {
		$decision = apply_filters(
			'auto_update_theme',
			null,
			$this->offer(
				array(
					'id'    => self::THEME_ID,
					'theme' => self::THEME_STYLESHEET,
				)
			)
		);

		$this->assertTrue( $decision );
	}

	/**
	 * Our plugin updates itself.
	 *
	 * @return void
	 */
	public function test_our_plugin_opts_in(): void {
		$decision = apply_filters(
			'auto_update_plugin',
			null,
			$this->offer(
				array(
					'id'     => self::PLUGIN_ID,
					'plugin' => self::PLUGIN_FILE,
				)
			)
		);

		$this->assertTrue( $decision );
	}

	/**
	 * A wordpress.org-hosted theme is left exactly as core decided.
	 *
	 * @return void
	 */
	public function test_a_third_party_theme_is_left_alone(): void {
		$offer = $this->offer(
			array(
				'id'    => 'w.org/themes/twentytwentyfive',
				'theme' => 'twentytwentyfive',
			)
		);

		$this->assertNull( apply_filters( 'auto_update_theme', null, $offer ) );
		$this->assertFalse( apply_filters( 'auto_update_theme', false, $offer ) );
		$this->assertTrue( apply_filters( 'auto_update_theme', true, $offer ) );
	}

	/**
	 * A wordpress.org-hosted plugin is left exactly as core decided.
	 *
	 * @return void
	 */
	public function test_a_third_party_plugin_is_left_alone(): void {
		$offer = $this->offer(
			array(
				'id'     => 'w.org/plugins/stackable-ultimate-gutenberg-blocks',
				'plugin' => 'stackable-ultimate-gutenberg-blocks/plugin.php',
			)
		);

		$this->assertNull( apply_filters( 'auto_update_plugin', null, $offer ) );
		$this->assertFalse( apply_filters( 'auto_update_plugin', false, $offer ) );
	}

	/**
	 * Resolving via `id` is not enough on its own; the identity field must also match.
	 *
	 * `id` here is our theme's own canonical Update URI, so it resolves straight
	 * to the configured package — but the offer names a different stylesheet.
	 * That mismatch has to refuse the opt-in rather than trust `id` alone.
	 *
	 * @return void
	 */
	public function test_our_id_alone_does_not_enable_a_mismatched_identity(): void {
		$decision = apply_filters(
			'auto_update_theme',
			null,
			$this->offer(
				array(
					'id'    => self::THEME_ID,
					'theme' => 'somebody-elses-theme',
				)
			)
		);

		$this->assertNull( $decision );
	}

	/**
	 * Our stylesheet is not enough on its own either; `id` must resolve to our package.
	 *
	 * @return void
	 */
	public function test_our_identity_alone_does_not_enable_a_foreign_offer(): void {
		$decision = apply_filters(
			'auto_update_theme',
			null,
			$this->offer(
				array(
					'id'    => 'https://updates.example.invalid/demo/theme-fx-demo-theme',
					'theme' => self::THEME_STYLESHEET,
				)
			)
		);

		$this->assertNull( $decision );
	}

	/**
	 * A malformed offer is passed straight through rather than crashing the run.
	 *
	 * `WP_Automatic_Updater` also applies these filters for `core` and
	 * `translation`, where the offer has neither `theme` nor `plugin`.
	 *
	 * @return void
	 */
	public function test_an_offer_without_an_id_is_passed_through(): void {
		$this->assertNull( apply_filters( 'auto_update_theme', null, (object) array() ) );
		$this->assertTrue( apply_filters( 'auto_update_plugin', true, (object) array( 'id' => 42 ) ) );
		$this->assertNull( apply_filters( 'auto_update_plugin', null, 'not an object' ) );
	}
}
