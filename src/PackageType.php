<?php
/**
 * The two kinds of thing this site updates.
 *
 * @package Fanxie\WpUpdates
 */

declare( strict_types=1 );

namespace Fanxie\WpUpdates;

/**
 * A package kind, and everything that differs between the two.
 *
 * WordPress treats themes and plugins almost identically here, but not quite:
 * the filter names differ, and — verified against WordPress 7.1's
 * `wp-includes/update.php` — core forces `$update->plugin` onto a plugin offer
 * while it does **not** force `$update->theme` onto a theme offer. A theme
 * response that omits `theme` is still accepted into the transient and then
 * breaks `WP_Automatic_Updater::update()`, which reads `$item->theme` to decide
 * what to upgrade. Keeping the difference in one enum is how we stop
 * rediscovering it.
 */
enum PackageType: string {

	case Theme  = 'theme';
	case Plugin = 'plugin';

	/**
	 * Name of the host-scoped filter core applies to collect an update offer.
	 *
	 * `update_themes_{$hostname}` (since 6.1) and `update_plugins_{$hostname}`
	 * (since 5.8); both still present, and still four-argument, in WordPress 7.1.
	 *
	 * @param string $hostname Hostname from the package's `Update URI` header.
	 * @return string
	 */
	public function offer_filter( string $hostname ): string {
		return match ( $this ) {
			self::Theme  => 'update_themes_' . $hostname,
			self::Plugin => 'update_plugins_' . $hostname,
		};
	}

	/**
	 * Name of the filter `WP_Automatic_Updater::should_update()` applies.
	 *
	 * @return string
	 */
	public function auto_update_filter(): string {
		return 'auto_update_' . $this->value;
	}

	/**
	 * The offer key core uses to identify the item being updated.
	 *
	 * For plugins this is the plugin file (`fx-demo/fx-demo.php`); for themes it
	 * is the stylesheet directory (`fx-demo-theme`). `WP_Automatic_Updater` reads
	 * `$item->{$type}` for both, which is why the field name is the enum value.
	 *
	 * @return string
	 */
	public function identity_field(): string {
		return $this->value;
	}
}
