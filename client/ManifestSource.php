<?php
/**
 * Fetching, caching and re-verifying the update manifest.
 *
 * @package FanxieLab\WpUpdates
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates;

/**
 * The network half of the update client.
 *
 * Two decisions worth stating out loud:
 *
 * **The cache stores the signed envelope, not the parsed manifest.** Every read
 * re-runs `sodium_crypto_sign_verify_detached()`. That costs tens of
 * microseconds and buys the property that a writable object cache — a shared
 * Redis, a persistent-cache plugin, a `wp_options` row — cannot inject an
 * update offer. Caching the *conclusion* would have made the signature check a
 * one-time formality.
 *
 * **A failure is cached too.** `wp_update_plugins()` runs on `admin_init`; an
 * update host that is down would otherwise mean a blocking HTTP request with
 * every admin page load. A short negative TTL keeps a soft failure soft.
 */
final class ManifestSource {

	/**
	 * How long a verified envelope is reused.
	 */
	public const SUCCESS_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long a failure suppresses another attempt.
	 */
	public const FAILURE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Seconds to wait for the manifest host.
	 */
	public const TIMEOUT = 8;

	/**
	 * Constructor.
	 *
	 * @param UpdateConfig $config   This build's update service configuration.
	 * @param Verifier     $verifier Signature verification and manifest parsing.
	 * @param Log          $log      Where refusals go.
	 */
	public function __construct(
		private readonly UpdateConfig $config,
		private readonly Verifier $verifier,
		private readonly Log $log
	) {}

	/**
	 * Site-transient key for one package's cached envelope.
	 *
	 * Static so the integration harness can clear caches without an instance.
	 *
	 * @param string  $hook_prefix The config's hook prefix.
	 * @param Package $package     The package.
	 * @return string
	 */
	public static function transient_key( string $hook_prefix, Package $package ): string {
		return $hook_prefix . '_upd_' . $package->type->value . '_' . $package->slug;
	}

	/**
	 * The manifest for one package, or null if there isn't a trustworthy one.
	 *
	 * Never throws. Every failure path returns null and logs, because this is
	 * called from inside a core filter during an admin request: an exception
	 * here would take down the update screen, and a warning would be printed
	 * into somebody's dashboard.
	 *
	 * @param Package $package The package to fetch a manifest for. Ownership of the
	 *                         `Update URI` this came from was already resolved by
	 *                         UpdateConfig::package_for_update_uri(); this method only
	 *                         needs to know which package it is fetching for.
	 * @return Manifest|null
	 */
	public function manifest_for( Package $package ): ?Manifest {
		$manifest_url = $this->config->manifest_url( $package );
		$transient    = self::transient_key( $this->config->hook_prefix, $package );
		$cached       = get_site_transient( $transient );

		if ( is_array( $cached ) && array_key_exists( 'body', $cached ) ) {
			$body = $cached['body'];

			if ( ! is_string( $body ) ) {
				// A recent attempt already failed and already logged. Stay quiet.
				return null;
			}

			$manifest = $this->open( $body, 'cache' );

			if ( null === $manifest ) {
				// The cache holds something that does not verify. Do not keep
				// reading it, and do not immediately re-fetch either: a poisoned
				// cache and a bad publish look identical from here.
				set_site_transient( $transient, array( 'body' => null ), self::FAILURE_TTL );
			}

			return $manifest;
		}

		$body = $this->request( $manifest_url );

		if ( null === $body ) {
			set_site_transient( $transient, array( 'body' => null ), self::FAILURE_TTL );

			return null;
		}

		$manifest = $this->open( $body, 'network' );

		set_site_transient(
			$transient,
			array( 'body' => null === $manifest ? null : $body ),
			null === $manifest ? self::FAILURE_TTL : self::SUCCESS_TTL
		);

		return $manifest;
	}

	/**
	 * Fetch the envelope. Returns null on any transport-level problem.
	 *
	 * @param string $url Absolute HTTPS URL of the manifest.
	 * @return string|null
	 */
	private function request( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'sslverify'   => true,
				'user-agent'  => 'fanxie-wp-update-client/1; ' . home_url( '/' ),
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log->refused(
				'Update manifest could not be fetched.',
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				)
			);

			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->log->refused(
				'Update manifest request returned an unexpected status.',
				array(
					'url'    => $url,
					'status' => $code,
				)
			);

			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			$this->log->refused( 'Update manifest response was empty.', array( 'url' => $url ) );

			return null;
		}

		return $body;
	}

	/**
	 * Verify and parse, converting the one exception into null plus a log line.
	 *
	 * @param string $body   Raw envelope.
	 * @param string $source 'cache' or 'network', so a poisoned cache is distinguishable in the log.
	 * @return Manifest|null
	 */
	private function open( string $body, string $source ): ?Manifest {
		try {
			return $this->verifier->open( $body );
		} catch ( ManifestError $error ) {
			$this->log->refused(
				$error->getMessage(),
				array_merge( $error->context(), array( 'source' => $source ) )
			);

			return null;
		}
	}
}
