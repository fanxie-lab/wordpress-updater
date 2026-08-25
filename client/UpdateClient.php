<?php
/**
 * The update client: what answers WordPress when it asks about our packages.
 *
 * @package FanxieLab\WpUpdates
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates;

/**
 * Hooks core's host-scoped update filters and answers them, or refuses.
 *
 * Registration is one line per companion plugin (or theme), from wherever it
 * boots:
 *
 *     UpdateClient::register( $config );
 *
 * `init` is early enough. Verified against WordPress 7.1: `wp_update_plugins()`
 * and `wp_update_themes()` are reached from `admin_init`, from the
 * `wp_update_plugins` / `wp_update_themes` cron events, and from the
 * `load-plugins.php` / `load-themes.php` screen hooks — all of which run after
 * `init` — and `WP_Automatic_Updater` runs later still, on `wp_version_check`.
 *
 * ## What core actually does with what we return (WordPress 7.1, wp-includes/update.php)
 *
 * - The filter is `apply_filters( "update_plugins_{$hostname}", false,
 *   $plugin_data, $plugin_file, $locales )` — **four** arguments, the fourth
 *   being installed locales for translation packages.
 * - A falsy return is skipped. A truthy return is cast with `(object)` and
 *   discarded unless it has a `version` property.
 * - Core then overwrites `id` and (plugins only) `plugin`, back-fills
 *   `new_version` from `version`, and **does the version comparison itself**:
 *   newer than installed goes to `$transient->response`, anything else to
 *   `$transient->no_update`. Returning an older version therefore cannot
 *   produce an update offer even if we tried.
 * - Themes are stored in the transient as arrays, plugins as objects.
 *
 * ## Fail closed — owned, not blanket
 *
 * Every path that cannot end in a verified signature for a package **this
 * client owns** returns `false` — including when some other callback has
 * already put a value in `$update`. We own the packages named in our config;
 * passing a stranger's array through for one of *those* would let anything on
 * the site hand the upgrader a package URL under our name.
 *
 * That is different from the single-tenant reference this was extracted from.
 * There, one client answered one host and "not ours" never happened — the
 * filters exist only if a package with that `Update URI` host is installed.
 * Here, every namespace on `wp-updates.fanxie.cloud` shares the same two
 * hostname-derived filters (spec §2), so several registered clients see every
 * check for every namespace. An Update URI this instance does not own is not
 * a refusal to make — it belongs to a sibling client, and the correct answer
 * is to leave `$update` exactly as received so that sibling's own verified
 * offer (or an earlier callback's) survives. See `dispatch()`, and the spec
 * amendment under §2.
 *
 * ## Why the hook callbacks are static
 *
 * The filters are attached as `array( self::class, 'on_…' )`, not as closures
 * bound to an instance. WordPress 7.1's `_wp_filter_build_unique_id()` gives a
 * closure the id `(string) spl_object_id( $callback )` and a static array
 * callable the id `'Class::method'`. Only the second is stable, and three
 * things depend on that:
 *
 * 1. **Attaching is genuinely idempotent.** Registering another config for a
 *    host already hooked writes to the same key in `WP_Hook::$callbacks[10]`,
 *    so it is not possible to end up with two dispatchers answering one
 *    filter — `on_theme_update()` / `on_plugin_update()` always iterate every
 *    registered instance themselves.
 * 2. **`remove_filter()` works from anywhere**, including from a static context
 *    holding no instance. A closure can only be removed by the object that
 *    added it, so a callback whose owner has been forgotten is stuck in
 *    `$wp_filter` for good. `spl_object_id()` is also reused after garbage
 *    collection, so a forgotten closure's id can later collide with an
 *    unrelated object's.
 * 3. **A site owner can turn us off.** `remove_filter(
 *    'update_plugins_wp-updates.fanxie.cloud', array( UpdateClient::class,
 *    'on_plugin_update' ) )` is a line somebody can actually write. There is no
 *    way to write that line against a closure.
 */
final class UpdateClient {

	/**
	 * The priority every one of our filters is attached at.
	 */
	public const PRIORITY = 10;

	/**
	 * Registered clients, keyed `"{host}/{namespace}"`.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = array();

	/**
	 * Hosts whose four filters are currently attached.
	 *
	 * @var list<string>
	 */
	private static array $hooked_hosts = array();

	/**
	 * Constructor.
	 *
	 * @param UpdateConfig      $config This client's compile-time configuration.
	 * @param ManifestSource|null $source Injected in tests; built lazily otherwise.
	 */
	public function __construct(
		private readonly UpdateConfig $config,
		private readonly ?ManifestSource $source = null
	) {}

	/**
	 * Register a client for one `{host}/{namespace}` key.
	 *
	 * Called twice for the same key with no source, the first registration
	 * stands. Called *with* a source, that source is always installed,
	 * replacing whatever was registered before: the parameter is documented as
	 * an override and has to behave like one. Silently discarding it lets a
	 * test that injects a verifier end up exercising the shipped default
	 * instead, and pass.
	 *
	 * @param UpdateConfig       $config This client's compile-time configuration.
	 * @param ManifestSource|null $source Optional override. Tests inject; production does not.
	 * @return self The registered client for this key.
	 */
	public static function register( UpdateConfig $config, ?ManifestSource $source = null ): self {
		$key = $config->host . '/' . $config->namespace;

		if ( isset( self::$instances[ $key ] ) && null === $source ) {
			return self::$instances[ $key ];
		}

		self::$instances[ $key ] = new self( $config, $source );
		self::hooks( $config->host );

		return self::$instances[ $key ];
	}

	/**
	 * Detach every filter for every hooked host, and forget every registered client.
	 *
	 * Safe to call when nothing is registered, and — because the callbacks are
	 * identified by name rather than by object — it detaches them even when the
	 * instances that attached them are long gone. The WordPress test suite
	 * depends on exactly that: `WP_UnitTestCase::tear_down()` restores a
	 * snapshot of `$wp_filter` taken after the plugin booted, so every test
	 * after the first one in a run begins with our filters re-attached and no
	 * instance behind them.
	 *
	 * @return void
	 */
	public static function reset(): void {
		foreach ( self::$hooked_hosts as $host ) {
			foreach ( self::filters_for_host( $host ) as $hook => $binding ) {
				remove_filter( $hook, $binding[0], self::PRIORITY );
			}
		}

		self::$hooked_hosts = array();
		self::$instances    = array();
	}

	/**
	 * Attach the four filters for one host, once.
	 *
	 * @param string $host Update host to hook.
	 * @return void
	 */
	private static function hooks( string $host ): void {
		if ( in_array( $host, self::$hooked_hosts, true ) ) {
			return;
		}

		foreach ( self::filters_for_host( $host ) as $hook => $binding ) {
			add_filter( $hook, $binding[0], self::PRIORITY, $binding[1] );
		}

		self::$hooked_hosts[] = $host;
	}

	/**
	 * The four filters one host answers: hook name to callback and accepted argument count.
	 *
	 * One list, read by both `hooks()` and `reset()`, so the two can never
	 * disagree about what is attached.
	 *
	 * @param string $host Update host.
	 * @return array<string, array{callable, int}>
	 */
	private static function filters_for_host( string $host ): array {
		return array(
			PackageType::Theme->offer_filter( $host )  => array( array( self::class, 'on_theme_update' ), 3 ),
			PackageType::Plugin->offer_filter( $host ) => array( array( self::class, 'on_plugin_update' ), 3 ),
			PackageType::Theme->auto_update_filter()   => array( array( self::class, 'on_auto_update' ), 2 ),
			PackageType::Plugin->auto_update_filter()  => array( array( self::class, 'on_auto_update' ), 2 ),
		);
	}

	/**
	 * Hook callback for `update_themes_{$host}`.
	 *
	 * @param mixed                $update     Whatever a previous callback returned.
	 * @param array<string, mixed> $theme_data Theme headers.
	 * @param string               $stylesheet Stylesheet directory of the theme being checked.
	 * @return mixed
	 */
	public static function on_theme_update( mixed $update, array $theme_data, string $stylesheet ): mixed {
		return self::dispatch( PackageType::Theme, $update, $theme_data, $stylesheet );
	}

	/**
	 * Hook callback for `update_plugins_{$host}`.
	 *
	 * @param mixed                $update      Whatever a previous callback returned.
	 * @param array<string, mixed> $plugin_data Plugin headers.
	 * @param string               $plugin_file Plugin file, relative to the plugins directory.
	 * @return mixed
	 */
	public static function on_plugin_update( mixed $update, array $plugin_data, string $plugin_file ): mixed {
		return self::dispatch( PackageType::Plugin, $update, $plugin_data, $plugin_file );
	}

	/**
	 * Shared body of both offer filters: find the owning instance, or pass through.
	 *
	 * @param PackageType          $type     Theme or plugin — which filter this is.
	 * @param mixed                $update   Whatever a previous callback returned.
	 * @param array<string, mixed> $headers  Package headers from core.
	 * @param string               $identity Stylesheet, or plugin file.
	 * @return mixed
	 */
	private static function dispatch( PackageType $type, mixed $update, array $headers, string $identity ): mixed {
		$uri = isset( $headers['UpdateURI'] ) && is_string( $headers['UpdateURI'] ) ? $headers['UpdateURI'] : '';

		if ( '' !== $uri ) {
			foreach ( self::$instances as $client ) {
				$package = $client->config->package_for_update_uri( $uri );

				if ( null !== $package ) {
					// Owned: this client's answer stands, offer or refusal. Fail closed.
					return $client->offer( $package, $type, $headers, $identity );
				}
			}
		}

		/*
		 * Not ours. Every namespace shares these two filters (the name is
		 * derived from the host alone), so an unowned URI belongs to a sibling
		 * client and its value must survive us. We never *produce* an offer for
		 * a package we don't own — that is the invariant; unconditionally
		 * returning false here would instead wipe a sibling's verified offer
		 * whenever we happen to run after it.
		 */
		return $update;
	}

	/**
	 * Hook callback for `auto_update_theme` and `auto_update_plugin`.
	 *
	 * Iterates every registered instance; the first that recognises the item as
	 * its own and opts it in wins. With nothing registered, or nothing
	 * recognising the item, the incoming value is handed straight back, `null`
	 * included — core reads `null` as "nothing has hooked this filter at all".
	 *
	 * @param mixed $enabled Whether core would auto-update, or null if undecided.
	 * @param mixed $item    The update offer.
	 * @return mixed
	 */
	public static function on_auto_update( mixed $enabled, mixed $item ): mixed {
		foreach ( self::$instances as $client ) {
			if ( true === $client->auto_update( $enabled, $item ) ) {
				return true;
			}
		}

		return $enabled;
	}

	/**
	 * Answer `auto_update_theme` / `auto_update_plugin` for this instance's config.
	 *
	 * Scoped twice over: the offer's `id` must resolve to one of this config's
	 * packages, *and* the offer's identity field (`theme` or `plugin`) must
	 * match that package's identity exactly. Anything else is passed through
	 * untouched — including `null`, which core uses to detect that nothing has
	 * hooked the filter at all.
	 *
	 * @param mixed $enabled Whether core would auto-update, or null if undecided.
	 * @param mixed $item    The update offer.
	 * @return mixed
	 */
	public function auto_update( mixed $enabled, mixed $item ): mixed {
		if ( ! is_object( $item ) || ! isset( $item->id ) || ! is_string( $item->id ) ) {
			return $enabled;
		}

		$package = $this->config->package_for_update_uri( $item->id );

		if ( null === $package ) {
			return $enabled;
		}

		$field    = $package->type->identity_field();
		$identity = isset( $item->{$field} ) && is_string( $item->{$field} ) ? $item->{$field} : '';

		return $identity === $package->identity ? true : $enabled;
	}

	/**
	 * Answer for a package this instance's config owns.
	 *
	 * @param Package              $package     The owned package `package_for_update_uri()` resolved.
	 * @param PackageType          $checked_type Which filter fired — theme or plugin.
	 * @param array<string, mixed> $headers     Package headers from core.
	 * @param string               $identity    Stylesheet, or plugin file, of the package being checked.
	 * @return array<string, string>|false
	 */
	private function offer( Package $package, PackageType $checked_type, array $headers, string $identity ): array|false {
		$update_uri = isset( $headers['UpdateURI'] ) && is_string( $headers['UpdateURI'] ) ? $headers['UpdateURI'] : '';
		$installed  = isset( $headers['Version'] ) && is_string( $headers['Version'] ) ? $headers['Version'] : '';

		if ( $package->type !== $checked_type || $package->identity !== $identity ) {
			$this->log()->refused(
				'Update URI is ours but the package being checked is not the one it names.',
				array(
					'expected' => $package->type->value . ':' . $package->identity,
					'checked'  => $checked_type->value . ':' . $identity,
				)
			);

			return false;
		}

		$manifest = $this->source()->manifest_for( $package );

		if ( null === $manifest ) {
			return false;
		}

		if ( $manifest->type !== $package->type || $manifest->slug !== $package->slug ) {
			$this->log()->refused(
				'Verified manifest describes a different package than the one being checked.',
				array(
					'expected' => $package->type->value . ':' . $package->slug,
					'found'    => $manifest->type->value . ':' . $manifest->slug,
				)
			);

			return false;
		}

		if ( '' !== $installed && ! $manifest->is_newer_than( $installed ) ) {
			/*
			 * Still returned, not suppressed. Core routes a non-newer offer into
			 * $transient->no_update, which is what makes the "Auto-updates
			 * enabled" column appear on the Plugins and Themes screens. Core's own
			 * version_compare() is what decides; ours only decides what to log.
			 */
			$this->log()->refused(
				'Manifest is not newer than the installed version; no update will be offered.',
				array(
					'installed' => $installed,
					'offered'   => $manifest->version,
				)
			);
		}

		return $manifest->to_offer( $identity, $update_uri );
	}

	/**
	 * The manifest source: injected, or built lazily from the compiled-in trust anchor.
	 *
	 * Built here rather than in the constructor so that an empty compiled key
	 * still flows straight into Verifier — every update refused and logged
	 * (spec §4) — without needing a special case at registration time.
	 *
	 * @return ManifestSource
	 */
	private function source(): ManifestSource {
		return $this->source ?? new ManifestSource(
			$this->config,
			new Verifier( $this->config->resolve_public_key(), $this->config->host, $this->config->namespace ),
			new Log( $this->config->hook_prefix )
		);
	}

	/**
	 * A log sink for this config's hook prefix. Cheap enough to build on demand.
	 *
	 * @return Log
	 */
	private function log(): Log {
		return new Log( $this->config->hook_prefix );
	}
}
