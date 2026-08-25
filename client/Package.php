<?php
/**
 * One package this build publishes updates for.
 *
 * @package FanxieLab\WpUpdates
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates;

/**
 * A theme or plugin, as the update client identifies it.
 */
final readonly class Package {

	/**
	 * Constructor.
	 *
	 * @param PackageType $type     Theme or plugin.
	 * @param string      $slug     Directory name in wp-content ('fx-demo-theme', 'fx-demo').
	 * @param string      $identity Stylesheet ('fx-demo-theme') or plugin file ('fx-demo/fx-demo.php').
	 */
	public function __construct(
		public PackageType $type,
		public string $slug,
		public string $identity,
	) {}
}
