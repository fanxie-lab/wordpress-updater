<?php
/**
 * Integration tests: a fake manifest, driven through WordPress core's own update path.
 *
 * @package FanxieLab\WpUpdates\Tests
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Integration;

use FanxieLab\WpUpdates\Tests\Support\SignedManifest;
use FanxieLab\WpUpdates\{Log, ManifestSource, Package, PackageType, UpdateClient, UpdateConfig, Verifier};
use stdClass;
use WP_UnitTestCase;

/**
 * Everything here goes through `wp_update_themes()` and `wp_update_plugins()`,
 * with two deliberate exceptions:
 * `test_an_unowned_update_uri_passes_a_previous_callback_value_through()` and
 * `test_two_registered_namespaces_each_answer_their_own_manifest()` call
 * `apply_filters()` directly instead. What those two are proving is
 * filter-chain behaviour across two namespaces sharing one hostname-derived
 * filter — one client's callback must leave a sibling's offer untouched, and
 * a second registered namespace must answer for its own package — not what
 * core writes into a transient for a package. The second of the two even
 * names a package, `fx-two`, that is never installed on disk, so there is no
 * transient for it to drive through in the first place.
 *
 * Every other test here drives the real core function and asserts on
 * `get_site_transient()`. Calling `apply_filters( 'update_themes_wp-updates.fanxie.cloud', … )`
 * directly for those would be easier and would prove less: it would not catch
 * a hook attached under the wrong name, would not exercise core's own version
 * comparison, and would not show what core actually stores in the transient —
 * which is the thing the upgrader later reads.
 */
final class UpdateFilterTest extends WP_UnitTestCase {

	use SignedManifest;

	/**
	 * The plugin file our fixture plugin is installed under.
	 */
	private const PLUGIN_FILE = 'fx-demo/fx-demo.php';

	/**
	 * Where our theme's manifest lives.
	 */
	private const THEME_MANIFEST = 'https://wp-updates.fanxie.cloud/demo/theme-fx-demo-theme.json';

	/**
	 * Where our plugin's manifest lives.
	 */
	private const PLUGIN_MANIFEST = 'https://wp-updates.fanxie.cloud/demo/plugin-fx-demo.json';

	/**
	 * Stand up a keypair, an HTTP harness and a registered client.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->start_update_harness();
	}

	/**
	 * Put every filter and transient back.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->stop_update_harness();
		parent::tear_down();
	}

	/**
	 * This suite's theme, as demo_config() knows it — for building a transient key.
	 *
	 * @return Package
	 */
	private function theme_package(): Package {
		return $this->demo_config()->packages[0];
	}

	/**
	 * A tenant config identical to demo_config() except for its public key —
	 * used by the tests that need a client which can verify nothing.
	 *
	 * @param string $public_key Public key to configure, typically ''.
	 * @return UpdateConfig
	 */
	private function blank_config( string $public_key = '' ): UpdateConfig {
		return new UpdateConfig(
			host: 'wp-updates.fanxie.cloud',
			namespace: 'demo',
			public_key: $public_key,
			hook_prefix: 'fxdemo',
			packages: $this->demo_config()->packages,
		);
	}

	/**
	 * The theme update transient, after core has rebuilt it.
	 *
	 * @return stdClass
	 */
	private function refresh_theme_updates(): stdClass {
		delete_site_transient( 'update_themes' );
		wp_update_themes();

		$transient = get_site_transient( 'update_themes' );

		$this->assertInstanceOf( stdClass::class, $transient, 'Core always writes the transient, even when it offers nothing.' );

		return $transient;
	}

	/**
	 * The plugin update transient, after core has rebuilt it.
	 *
	 * @return stdClass
	 */
	private function refresh_plugin_updates(): stdClass {
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$transient = get_site_transient( 'update_plugins' );

		$this->assertInstanceOf( stdClass::class, $transient, 'Core always writes the transient, even when it offers nothing.' );

		return $transient;
	}

	/**
	 * Registration attaches exactly the four filters core will look for.
	 *
	 * This is the assertion a one-line wiring mistake fails on. Everything else
	 * in this file would still pass if `register()` hooked nothing, because core
	 * would simply never call us and no update would ever be offered — which
	 * looks identical to "there is no update".
	 *
	 * The callbacks are asserted *by name*. That is the guarantee, not an
	 * incidental detail: a closure's hook id is `spl_object_id()`, so closures
	 * cannot be removed by anyone but the object that added them, cannot be
	 * de-duplicated across two registrations, and cannot be unhooked by a site
	 * owner. Reverting these to `$this->method( ... )` must fail here.
	 *
	 * @return void
	 */
	public function test_registration_attaches_the_filters_core_will_call(): void {
		$this->assertFalse(
			has_filter( 'update_themes_wp-updates.fanxie.cloud' ),
			'The harness detached whatever a previous test registered.'
		);

		$first = $this->register_client();

		$expected = array(
			'update_themes_wp-updates.fanxie.cloud'  => 'on_theme_update',
			'update_plugins_wp-updates.fanxie.cloud' => 'on_plugin_update',
			'auto_update_theme'                      => 'on_auto_update',
			'auto_update_plugin'                     => 'on_auto_update',
		);

		foreach ( $expected as $hook => $method ) {
			$this->assertSame(
				UpdateClient::PRIORITY,
				has_filter( $hook, array( UpdateClient::class, $method ) ),
				$hook . ' is answered by a callback that can be named, and therefore removed.'
			);
		}

		$this->assertSame(
			$first,
			UpdateClient::register( $this->demo_config() ),
			'Registering twice does not hook twice.'
		);

		UpdateClient::reset();

		foreach ( $expected as $hook => $method ) {
			$this->assertFalse( has_filter( $hook, array( UpdateClient::class, $method ) ), $hook . ' is detached.' );
		}
	}

	/**
	 * Registering with a source replaces the client instead of stacking beside it.
	 *
	 * This is the regression test for the bug that shipped: `register()` used to
	 * return early when an instance already existed, silently discarding the
	 * source it was handed, and it attached bound closures that a later `reset()`
	 * could not detach. Between them, a second registration left *two* clients on
	 * one filter. The first to run would fetch the manifest, fail to verify it
	 * against the wrong key, and negative-cache the failure; the second would then
	 * read that negative cache and stay silent. Both an update offer and the
	 * refusal that should have explained its absence disappeared.
	 *
	 * So: register a client that can verify nothing, then register the real one,
	 * and require that the first is gone rather than merely outvoted.
	 *
	 * @return void
	 */
	public function test_registering_again_replaces_the_client_rather_than_stacking(): void {
		$next = $this->newer_than( $this->installed_theme_version() );

		$this->serve( self::THEME_MANIFEST, $this->envelope( $this->theme_payload( $next ) ) );

		// A client with no trust anchor — the shipped default, which refuses everything.
		$blank = $this->blank_config();
		UpdateClient::register(
			$blank,
			new ManifestSource( $blank, new Verifier( '', $blank->host, $blank->namespace ), new Log( 'fxdemo' ) )
		);

		// Now the one that holds the key this manifest was signed with.
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayHasKey( 'fx-demo-theme', $transient->response, 'The injected source is the one in effect.' );
		$this->assertSame( $next, $transient->response['fx-demo-theme']['new_version'] );
		$this->assertSame(
			array(),
			$this->refusals,
			'The replaced client must not still be running: any refusal here means two clients saw this manifest.'
		);
	}

	/**
	 * A verified manifest with a newer version produces a theme update offer.
	 *
	 * @return void
	 */
	public function test_a_good_signature_offers_a_theme_update(): void {
		$next = $this->newer_than( $this->installed_theme_version() );

		$this->serve( self::THEME_MANIFEST, $this->envelope( $this->theme_payload( $next ) ) );
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayHasKey( 'fx-demo-theme', $transient->response, 'The theme is offered an update.' );

		$offer = $transient->response['fx-demo-theme'];

		// Themes are stored as arrays; plugins as objects. Core's asymmetry, not ours.
		$this->assertIsArray( $offer );
		$this->assertSame( $next, $offer['new_version'] );
		$this->assertSame(
			'https://wp-updates.fanxie.cloud/demo/packages/theme-fx-demo-theme-' . $next . '.zip',
			$offer['package']
		);
		$this->assertSame(
			'fx-demo-theme',
			$offer['theme'],
			'WP_Automatic_Updater reads $item->theme; core never fills it in for us.'
		);
		$this->assertSame(
			'https://wp-updates.fanxie.cloud/demo/theme-fx-demo-theme',
			$offer['id'],
			'Core sets id from the Update URI header.'
		);
		$this->assertSame( array(), $this->refusals );
	}

	/**
	 * A verified manifest with a newer version produces a plugin update offer.
	 *
	 * @return void
	 */
	public function test_a_good_signature_offers_a_plugin_update(): void {
		$next = $this->newer_than( $this->installed_plugin_version() );

		$this->serve( self::PLUGIN_MANIFEST, $this->envelope( $this->plugin_payload( $next ) ) );
		$this->register_client();

		$transient = $this->refresh_plugin_updates();

		$this->assertArrayHasKey( self::PLUGIN_FILE, $transient->response );

		$offer = $transient->response[ self::PLUGIN_FILE ];

		// Plugins are stored as objects; themes as arrays. Core's asymmetry, not ours.
		$this->assertInstanceOf( stdClass::class, $offer );
		$this->assertSame( $next, $offer->new_version );
		$this->assertSame( self::PLUGIN_FILE, $offer->plugin, 'Core forces $update->plugin for plugins.' );
		$this->assertSame( 'fx-demo', $offer->slug );
		$this->assertStringEndsWith( '/plugin-fx-demo-' . $next . '.zip', $offer->package );
		$this->assertSame( array(), $this->refusals );
	}

	/**
	 * A manifest signed with the wrong key offers nothing, and says why.
	 *
	 * @return void
	 */
	public function test_a_bad_signature_offers_nothing_and_logs(): void {
		$next     = $this->newer_than( $this->installed_theme_version() );
		$impostor = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );

		$this->serve(
			self::THEME_MANIFEST,
			$this->envelope( $this->theme_payload( $next ), $impostor )
		);
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response, 'Nothing is offered.' );
		$this->assertArrayNotHasKey( 'fx-demo-theme', (array) $transient->no_update, 'Not even as a no-op entry.' );
		$this->assertContains( 'Update manifest signature does not verify.', $this->refusals );
	}

	/**
	 * A refusal reaches the PHP error log, not just the action.
	 *
	 * The action is what tests observe; `error_log()` is what an operator reads
	 * at three in the morning. Both have to work, so both are asserted.
	 *
	 * @return void
	 */
	public function test_a_refusal_reaches_the_php_error_log(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'Logging is deliberately silent unless WP_DEBUG is on.' );
		}

		$destination = tempnam( sys_get_temp_dir(), 'fxdemo-update-log-' );

		$this->assertIsString( $destination );

		$previous = ini_get( 'error_log' );

		// phpcs:ignore WordPress.PHP.IniSet.Risky
		if ( false === ini_set( 'error_log', $destination ) ) {
			$this->markTestSkipped( 'error_log cannot be redirected in this SAPI.' );
		}

		try {
			$impostor = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );

			$this->serve(
				self::THEME_MANIFEST,
				$this->envelope( $this->theme_payload( '99.0.0' ), $impostor )
			);
			$this->register_client();
			$this->refresh_theme_updates();

			// The file is a temporary PHP error log this test created; there is
			// no remote URL and no WP_Filesystem context here.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$written = file_get_contents( $destination );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky
			ini_set( 'error_log', false === $previous ? '' : $previous );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $destination );
		}

		$this->assertIsString( $written );
		$this->assertStringContainsString( '[fxdemo/update]', $written );
		$this->assertStringContainsString( 'signature does not verify', $written );
	}

	/**
	 * A manifest older than what is installed produces no update offer.
	 *
	 * Core does this comparison itself and files the offer under `no_update`,
	 * which is what makes the "Auto-updates enabled" column render. So the
	 * assertion is not "we returned false" — it is "nothing is offered", which
	 * is the behaviour anybody actually cares about.
	 *
	 * @return void
	 */
	public function test_a_lower_version_is_never_offered(): void {
		$this->serve( self::THEME_MANIFEST, $this->envelope( $this->theme_payload( '0.0.1' ) ) );
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response, 'No update is offered.' );
		$this->assertArrayHasKey(
			'fx-demo-theme',
			(array) $transient->no_update,
			'Core files a non-newer offer under no_update, which is what powers the auto-update UI.'
		);
		$this->assertContains( 'Manifest is not newer than the installed version; no update will be offered.', $this->refusals );
	}

	/**
	 * An unreachable manifest host degrades quietly: no offer, no fatal, no noise.
	 *
	 * @return void
	 */
	public function test_an_unreachable_host_fails_soft(): void {
		$this->serve_failure( self::THEME_MANIFEST );
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response );
		$this->assertIsInt( $transient->last_checked, 'Core still completed its check.' );
		$this->assertContains( 'Update manifest could not be fetched.', $this->refusals );

		// And the failure is remembered, so a down host is not re-dialled on every admin page load.
		$cached = get_site_transient( ManifestSource::transient_key( 'fxdemo', $this->theme_package() ) );

		$this->assertIsArray( $cached );
		$this->assertNull( $cached['body'] );
	}

	/**
	 * A non-200 from the manifest host is a failure, not an empty manifest.
	 *
	 * @return void
	 */
	public function test_a_502_from_the_manifest_host_offers_nothing(): void {
		$this->serve( self::THEME_MANIFEST, '<!doctype html><title>502 Bad Gateway</title>', 502 );
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response );
		$this->assertContains( 'Update manifest request returned an unexpected status.', $this->refusals );
	}

	/**
	 * A correctly signed manifest for something else is not applied to us.
	 *
	 * The manifest here is kept internally consistent — its `package` URL
	 * agrees with its own (different) `slug`, so it verifies and parses
	 * cleanly (Manifest::from_array()'s own namespace-path check, spec §5.7,
	 * would otherwise reject it a step earlier, for a different reason than
	 * the one this test is about).
	 *
	 * @return void
	 */
	public function test_a_manifest_for_another_package_is_refused(): void {
		$next = $this->newer_than( $this->installed_theme_version() );

		$this->serve(
			self::THEME_MANIFEST,
			$this->envelope(
				$this->theme_payload(
					$next,
					array(
						'slug'    => 'twentytwentyfive',
						'package' => 'https://wp-updates.fanxie.cloud/demo/packages/theme-twentytwentyfive-' . $next . '.zip',
					)
				)
			)
		);
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response );
		$this->assertArrayNotHasKey( 'twentytwentyfive', $transient->response, 'And certainly not applied to someone else.' );
		$this->assertContains(
			'Verified manifest describes a different package than the one being checked.',
			$this->refusals
		);
	}

	/**
	 * A signed manifest cannot point the upgrader at an arbitrary origin.
	 *
	 * @return void
	 */
	public function test_a_package_url_on_a_foreign_host_is_refused(): void {
		$next = $this->newer_than( $this->installed_theme_version() );

		$this->serve(
			self::THEME_MANIFEST,
			$this->envelope(
				$this->theme_payload( $next, array( 'package' => 'https://evil.example/fx-demo-theme.zip' ) )
			)
		);
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response );
		$this->assertContains( 'Manifest package URL points at an unexpected host.', $this->refusals );
	}

	/**
	 * The cached envelope is re-verified on read, so a writable cache is not a way in.
	 *
	 * @return void
	 */
	public function test_a_poisoned_cache_is_caught_on_read(): void {
		$next     = $this->newer_than( $this->installed_theme_version() );
		$impostor = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );

		// Nothing is served: if the client trusted its cache it would never notice.
		set_site_transient(
			ManifestSource::transient_key( 'fxdemo', $this->theme_package() ),
			array( 'body' => $this->envelope( $this->theme_payload( $next ), $impostor ) ),
			ManifestSource::SUCCESS_TTL
		);
		$this->register_client();

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response );
		$this->assertContains( 'Update manifest signature does not verify.', $this->refusals );

		$cached = get_site_transient( ManifestSource::transient_key( 'fxdemo', $this->theme_package() ) );

		$this->assertIsArray( $cached );
		$this->assertNull( $cached['body'], 'The poisoned entry is replaced, not left to be re-read.' );
	}

	/**
	 * With no key compiled in — the shipped default — nothing is ever offered.
	 *
	 * @return void
	 */
	public function test_the_client_offers_nothing_without_a_public_key(): void {
		$next = $this->newer_than( $this->installed_theme_version() );

		$this->serve( self::THEME_MANIFEST, $this->envelope( $this->theme_payload( $next ) ) );

		$blank = $this->blank_config();
		UpdateClient::register(
			$blank,
			new ManifestSource( $blank, new Verifier( '', $blank->host, $blank->namespace ), new Log( 'fxdemo' ) )
		);

		$transient = $this->refresh_theme_updates();

		$this->assertArrayNotHasKey( 'fx-demo-theme', $transient->response );
		$this->assertContains( 'No usable update signing key is compiled into this build.', $this->refusals );
	}

	/**
	 * A package URL outside our namespace is refused even with a valid signature.
	 *
	 * @return void
	 */
	public function test_a_package_url_outside_the_namespace_is_refused_despite_a_valid_signature(): void {
		$this->register_client();
		$payload            = $this->plugin_payload( $this->newer_than( $this->installed_plugin_version() ) );
		$payload['package'] = str_replace( '/demo/', '/stilotex/', $payload['package'] );
		$this->serve( self::PLUGIN_MANIFEST, $this->envelope( $payload ) );

		$transient = $this->refresh_plugin_updates();

		$this->assertArrayNotHasKey( 'fx-demo/fx-demo.php', (array) ( $transient->response ?? array() ) );
		$this->assertNotEmpty( $this->refusals );
	}

	/**
	 * An unowned Update URI leaves whatever a previous callback returned untouched.
	 *
	 * @return void
	 */
	public function test_an_unowned_update_uri_passes_a_previous_callback_value_through(): void {
		$this->register_client();
		$marker = array( 'id' => 'x', 'version' => '9.9.9', 'new_version' => '9.9.9', 'package' => 'https://sibling.example/x.zip' );

		$result = apply_filters(
			'update_plugins_wp-updates.fanxie.cloud',
			$marker,
			array( 'UpdateURI' => 'https://wp-updates.fanxie.cloud/stilotex/plugin-stilotex-core', 'Version' => '1.0.0' ),
			'stilotex-core/stilotex-core.php',
			null
		);

		$this->assertSame( $marker, $result, 'A sibling namespace\'s offer survives our callback.' );
	}

	/**
	 * Two registered namespaces on the same host each answer only their own manifest.
	 *
	 * @return void
	 */
	public function test_two_registered_namespaces_each_answer_their_own_manifest(): void {
		$this->register_client(); // demo

		$second = new UpdateConfig(
			host: 'wp-updates.fanxie.cloud',
			namespace: 'demo2',
			public_key: $this->signing_public,
			hook_prefix: 'fxdemo2',
			packages: array( new Package( PackageType::Plugin, 'fx-two', 'fx-two/fx-two.php' ) ),
		);
		UpdateClient::register(
			$second,
			new ManifestSource( $second, new Verifier( $this->signing_public, $second->host, 'demo2' ), new Log( 'fxdemo2' ) )
		);
		delete_site_transient( 'fxdemo2_upd_plugin_fx-two' );

		$payload = array(
			'type'    => 'plugin',
			'slug'    => 'fx-two',
			'version' => '2.0.0',
			'package' => 'https://wp-updates.fanxie.cloud/demo2/packages/plugin-fx-two-2.0.0.zip',
			'url'     => '',
		);
		$this->serve( 'https://wp-updates.fanxie.cloud/demo2/plugin-fx-two.json', $this->envelope( $payload ) );

		$result = apply_filters(
			'update_plugins_wp-updates.fanxie.cloud',
			false,
			array( 'UpdateURI' => 'https://wp-updates.fanxie.cloud/demo2/plugin-fx-two', 'Version' => '1.0.0' ),
			'fx-two/fx-two.php',
			null
		);

		$this->assertIsArray( $result );
		$this->assertSame( '2.0.0', $result['version'] );
		$this->assertSame( 'fx-two/fx-two.php', $result['plugin'] );
	}
}
