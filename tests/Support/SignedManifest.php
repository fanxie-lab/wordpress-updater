<?php
/**
 * Shared harness for driving the update filters with a fake manifest host.
 *
 * @package FanxieLab\WpUpdates\Tests
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Tests\Support;

/*
 * The Integration suite has WordPress, but `wp_json_encode()` would escape the
 * slashes in a package URL, and these fixtures are compared against literal
 * strings. base64 here is the wire format for a signature, not hidden code.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

use FanxieLab\WpUpdates\Log;
use FanxieLab\WpUpdates\ManifestSource;
use FanxieLab\WpUpdates\Package;
use FanxieLab\WpUpdates\PackageType;
use FanxieLab\WpUpdates\UpdateClient;
use FanxieLab\WpUpdates\UpdateConfig;
use FanxieLab\WpUpdates\Verifier;
use LogicException;
use WP_Error;

/**
 * Everything the update tests need to stand a signed manifest up in front of core.
 *
 * A fresh Ed25519 keypair per test, an HTTP layer that answers only the URLs a
 * test names, and a record of every refusal the client logged. Nothing here
 * reaches the network: an unlisted URL comes back as a WP_Error, so a test that
 * accidentally depends on a real request fails rather than passing slowly.
 */
trait SignedManifest {

	/**
	 * Base64 public key for this test's keypair.
	 *
	 * @var string
	 */
	private string $signing_public = '';

	/**
	 * Raw secret key for this test's keypair.
	 *
	 * @var string
	 */
	private string $signing_secret = '';

	/**
	 * URL to canned response, consulted by the `pre_http_request` filter.
	 *
	 * @var array<string, array<string, mixed>|WP_Error>
	 */
	private array $canned = array();

	/**
	 * Every refusal the update client logged during the test.
	 *
	 * @var list<string>
	 */
	private array $refusals = array();

	/**
	 * Stand the harness up. Call from `set_up()`.
	 *
	 * @return void
	 */
	private function start_update_harness(): void {
		$pair                 = sodium_crypto_sign_keypair();
		$this->signing_public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
		$this->signing_secret = sodium_crypto_sign_secretkey( $pair );
		$this->canned         = array();
		$this->refusals       = array();

		$this->forget_update_state();

		/*
		 * UpdateClient::$instances and $instances::$hooked_hosts are static, so
		 * they outlive any one test regardless of what WP_UnitTestCase::tear_down()
		 * restores in $wp_filter. Resetting explicitly, per test, is what keeps
		 * one test's registered client from still being reachable — and still
		 * answering filters under its own name — in the next one. This only works
		 * because UpdateClient's callbacks are named rather than closures; see the
		 * note on that class.
		 */
		UpdateClient::reset();

		add_filter( 'pre_http_request', array( $this, 'serve_canned_http' ), 10, 3 );
		add_action( 'fxdemo_update_refused', array( $this, 'record_refusal' ), 10, 1 );
	}

	/**
	 * Tear the harness down. Call from `tear_down()`.
	 *
	 * @return void
	 */
	private function stop_update_harness(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve_canned_http' ), 10 );
		remove_action( 'fxdemo_update_refused', array( $this, 'record_refusal' ), 10 );

		UpdateClient::reset();
		$this->forget_update_state();
	}

	/**
	 * Clear every transient the update path reads or writes.
	 *
	 * @return void
	 */
	private function forget_update_state(): void {
		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_plugins' );

		foreach ( $this->demo_config()->packages as $package ) {
			delete_site_transient( ManifestSource::transient_key( 'fxdemo', $package ) );
		}
	}

	/**
	 * The canonical demo tenant configuration, signed with this test's own key.
	 *
	 * @return UpdateConfig
	 */
	private function demo_config(): UpdateConfig {
		return new UpdateConfig(
			host: 'wp-updates.fanxie.cloud',
			namespace: 'demo',
			public_key: $this->signing_public,
			hook_prefix: 'fxdemo',
			packages: array(
				new Package( PackageType::Theme, 'fx-demo-theme', 'fx-demo-theme' ),
				new Package( PackageType::Plugin, 'fx-demo', 'fx-demo/fx-demo.php' ),
			),
		);
	}

	/**
	 * Register the update client with this test's public key.
	 *
	 * @return UpdateClient The registered client.
	 */
	private function register_client(): UpdateClient {
		$config = $this->demo_config();

		return UpdateClient::register(
			$config,
			new ManifestSource(
				$config,
				new Verifier( $this->signing_public, $config->host, $config->namespace ),
				new Log( 'fxdemo' )
			)
		);
	}

	/**
	 * Record a refusal. Public because it is used as a hook callback.
	 *
	 * @param string $message Reason the update was refused.
	 * @return void
	 */
	public function record_refusal( string $message ): void {
		$this->refusals[] = $message;
	}

	/**
	 * Answer a canned URL, or fail the request. Public: used as a hook callback.
	 *
	 * @param mixed                $preempt Whatever a previous callback returned.
	 * @param array<string, mixed> $args    Request arguments.
	 * @param string               $url     The URL being requested.
	 * @return array<string, mixed>|WP_Error
	 */
	public function serve_canned_http( mixed $preempt, array $args, string $url ): array|WP_Error {
		/*
		 * Core returns from wp_update_themes()/wp_update_plugins() before it ever
		 * reaches the `Update URI` loop unless wordpress.org answers 200 — see
		 * WordPress 7.1 wp-includes/update.php, the `is_wp_error( $raw_response )
		 * || 200 !== …` guard. An empty but well-formed answer is what a site with
		 * no wordpress.org-hosted packages would get, and all three keys must be
		 * present because core reads them without checking.
		 */
		if ( str_contains( $url, '//api.wordpress.org/themes/update-check' ) ) {
			return $this->http_ok( '{"themes":{},"no_update":{},"translations":[]}' );
		}

		if ( str_contains( $url, '//api.wordpress.org/plugins/update-check' ) ) {
			return $this->http_ok( '{"plugins":{},"no_update":{},"translations":[]}' );
		}

		if ( isset( $this->canned[ $url ] ) ) {
			return $this->canned[ $url ];
		}

		return new WP_Error( 'http_request_failed', 'No canned response for ' . $url );
	}

	/**
	 * Serve a body at a URL.
	 *
	 * @param string $url  Absolute URL.
	 * @param string $body Response body.
	 * @param int    $code HTTP status.
	 * @return void
	 */
	private function serve( string $url, string $body, int $code = 200 ): void {
		$this->canned[ $url ] = $this->http_ok( $body, $code );
	}

	/**
	 * Serve a transport failure at a URL, the way an unreachable host looks.
	 *
	 * @param string $url Absolute URL.
	 * @return void
	 */
	private function serve_failure( string $url ): void {
		$this->canned[ $url ] = new WP_Error(
			'http_request_failed',
			'cURL error 6: Could not resolve host: wp-updates.fanxie.cloud'
		);
	}

	/**
	 * Shape a response the way the WordPress HTTP API does.
	 *
	 * @param string $body Response body.
	 * @param int    $code HTTP status.
	 * @return array<string, mixed>
	 */
	private function http_ok( string $body, int $code = 200 ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Build a signed envelope around a manifest payload.
	 *
	 * @param array<string, mixed> $payload    Manifest fields.
	 * @param string|null          $secret_key Raw secret key, or null for this test's key.
	 * @return string
	 *
	 * @throws LogicException If the harness never generated a keypair.
	 */
	private function envelope( array $payload, ?string $secret_key = null ): string {
		$key = $secret_key ?? $this->signing_secret;

		if ( '' === $key ) {
			throw new LogicException( 'start_update_harness() has not run: there is no key to sign with.' );
		}

		$payload_json = (string) json_encode( $payload, JSON_UNESCAPED_SLASHES );

		return (string) json_encode(
			array(
				'schema'    => Verifier::SCHEMA,
				'payload'   => base64_encode( $payload_json ),
				'signature' => base64_encode( sodium_crypto_sign_detached( $payload_json, $key ) ),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * A manifest payload for our theme.
	 *
	 * @param string               $version   Version to offer.
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function theme_payload( string $version, array $overrides = array() ): array {
		return array_merge(
			array(
				'type'         => 'theme',
				'slug'         => 'fx-demo-theme',
				'version'      => $version,
				'package'      => 'https://wp-updates.fanxie.cloud/demo/packages/theme-fx-demo-theme-' . $version . '.zip',
				'url'          => '',
				'requires'     => '6.6',
				'requires_php' => '8.2',
				'tested'       => '7.1',
			),
			$overrides
		);
	}

	/**
	 * A manifest payload for our plugin.
	 *
	 * @param string               $version   Version to offer.
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function plugin_payload( string $version, array $overrides = array() ): array {
		return array_merge(
			array(
				'type'         => 'plugin',
				'slug'         => 'fx-demo',
				'version'      => $version,
				'package'      => 'https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-' . $version . '.zip',
				'url'          => '',
				'requires'     => '6.6',
				'requires_php' => '8.2',
				'tested'       => '7.1',
			),
			$overrides
		);
	}

	/**
	 * The version currently installed for our theme.
	 *
	 * @return string
	 */
	private function installed_theme_version(): string {
		$version = wp_get_theme( 'fx-demo-theme' )->get( 'Version' );

		return is_string( $version ) ? $version : '';
	}

	/**
	 * The version currently installed for our plugin.
	 *
	 * @return string
	 */
	private function installed_plugin_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( WP_PLUGIN_DIR . '/fx-demo/fx-demo.php', false, false );

		return is_string( $data['Version'] ) ? $data['Version'] : '';
	}

	/**
	 * A version certain to be newer than the one given.
	 *
	 * Derived rather than hardcoded: the installed versions move with every
	 * release, and a fixture that says "1.0.0 is newer" stops being true.
	 *
	 * @param string $version Installed version.
	 * @return string
	 */
	private function newer_than( string $version ): string {
		$major = (int) strtok( $version, '.' );

		return ( $major + 1 ) . '.0.0';
	}
}
