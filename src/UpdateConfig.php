<?php
/**
 * Everything one client build needs to know about its update service.
 *
 * @package Fanxie\WpUpdates
 */

declare( strict_types=1 );

namespace Fanxie\WpUpdates;

/**
 * Compile-time configuration for the update client.
 *
 * Constructed once, in the companion plugin (or theme), from literals: the
 * host, the namespace, the public key constant, and the package list are all
 * decided at build time. Nothing here is filterable — a trust anchor any
 * plugin can replace is not a trust anchor. The only runtime override is the
 * wp-config.php constant named by override_constant(), which implies
 * filesystem access and exists so staging can trust a staging key.
 */
final readonly class UpdateConfig {

	/**
	 * Constructor.
	 *
	 * @param string        $host        Update host, bare lowercase hostname ('wp-updates.fanxie.cloud').
	 *                                   Must match the host in every package's `Update URI` header —
	 *                                   core derives the filter names from the header's host.
	 * @param string        $namespace   Tenant namespace ('stilotex').
	 * @param string        $public_key  Base64 Ed25519 public key compiled into this build. Empty
	 *                                   means every update is refused and logged.
	 * @param string        $hook_prefix Prefix for the refusal action, transients and the override
	 *                                   constant ('stilotex'). Lowercase, [a-z0-9-].
	 * @param list<Package> $packages    The packages this build answers for.
	 */
	public function __construct(
		public string $host,
		public string $namespace,
		public string $public_key,
		public string $hook_prefix,
		public array $packages,
	) {}

	/**
	 * The canonical `Update URI` header value for a package.
	 *
	 * @param Package $package One of $this->packages.
	 * @return string
	 */
	public function update_uri( Package $package ): string {
		return 'https://' . $this->host . '/' . $this->namespace . '/' . $package->type->value . '-' . $package->slug;
	}

	/**
	 * Where the signed manifest for a package lives.
	 *
	 * @param Package $package One of $this->packages.
	 * @return string
	 */
	public function manifest_url( Package $package ): string {
		return $this->update_uri( $package ) . '.json';
	}

	/**
	 * The configured package that owns an Update URI, or null.
	 *
	 * Ownership is the FULL path — host, namespace, and `{type}-{slug}` — not
	 * the host alone: every namespace shares the two hostname-derived filters
	 * (spec §2), so the path is the only thing that distinguishes tenants.
	 * A trailing `.json` is tolerated because the header is an identifier.
	 *
	 * @param string $update_uri An `Update URI` header value.
	 * @return Package|null
	 */
	public function package_for_update_uri( string $update_uri ): ?Package {
		foreach ( $this->packages as $package ) {
			$canonical = $this->update_uri( $package );

			if ( $update_uri === $canonical || $update_uri === $canonical . '.json' ) {
				return $package;
			}
		}

		return null;
	}

	/**
	 * Name of the wp-config.php constant that can override the compiled key.
	 *
	 * @return string E.g. 'STILOTEX_UPDATE_PUBLIC_KEY'.
	 */
	public function override_constant(): string {
		return strtoupper( str_replace( '-', '_', $this->hook_prefix ) ) . '_UPDATE_PUBLIC_KEY';
	}

	/**
	 * The key this installation verifies against, base64-encoded.
	 *
	 * @return string Empty string when no key is configured.
	 */
	public function resolve_public_key(): string {
		$constant = $this->override_constant();

		if ( defined( $constant ) ) {
			$override = constant( $constant );

			if ( is_string( $override ) && '' !== trim( $override ) ) {
				return trim( $override );
			}
		}

		return $this->public_key;
	}

	/**
	 * Name of the action fired for every refusal.
	 *
	 * @return string E.g. 'stilotex_update_refused'.
	 */
	public function refused_action(): string {
		return $this->hook_prefix . '_update_refused';
	}
}
