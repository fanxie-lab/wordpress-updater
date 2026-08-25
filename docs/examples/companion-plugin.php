<?php
/**
 * Plugin Name: Stilotex Core
 * Description: Site functionality and signed self-hosted updates for stilotex.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Update URI: https://wp-updates.fanxie.cloud/stilotex/plugin-stilotex-core
 *
 * @package Stilotex
 */

declare( strict_types=1 );

use FanxieLab\WpUpdates\Package;
use FanxieLab\WpUpdates\PackageType;
use FanxieLab\WpUpdates\UpdateClient;
use FanxieLab\WpUpdates\UpdateConfig;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/UpdateKey.php';

add_action(
	'init',
	static function (): void {
		UpdateClient::register(
			new UpdateConfig(
				host: 'wp-updates.fanxie.cloud',
				namespace: 'stilotex',
				public_key: Stilotex\UpdateKey::COMPILED,
				hook_prefix: 'stilotex',
				packages: array(
					// This plugin itself…
					new Package( PackageType::Plugin, 'stilotex-core', 'stilotex-core/stilotex-core.php' ),
					// …and the theme it accompanies. One client per namespace
					// answers for every package in that namespace.
					new Package( PackageType::Theme, 'stilotex', 'stilotex' ),
				),
			)
		);
	}
);

/*
 * src/UpdateKey.php looks like this; `php vendor/fanxielab/wp-update-client/bin/release.php
 * keygen --write-to=src/UpdateKey.php` fills COMPILED in. It is a class constant on
 * purpose: not filterable, not in the database. The only override is the
 * STILOTEX_UPDATE_PUBLIC_KEY constant in wp-config.php (staging keys).
 *
 *     <?php
 *     namespace Stilotex;
 *     final class UpdateKey {
 *         public const COMPILED = '';
 *     }
 *
 * This file lives in the client repo's own layout (here, the companion
 * plugin's src/ directory) — it is unrelated to this library's own client/
 * directory, which holds FanxieLab\WpUpdates itself. Commit the public key
 * once it is filled in; never commit the secret half.
 */
