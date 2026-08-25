<?php
/**
 * Detached-signature verification for update manifests.
 *
 * @package FanxieLab\WpUpdates
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates;

/*
 * Every `throw` below constructs a ManifestError, which exists for exactly one
 * consumer: ManifestSource::open(), which catches it and hands the message to
 * Log. None of these strings is ever echoed, and escaping a byte count for a
 * log file would make an incident harder to read while protecting nothing. This
 * is the same call `phpcs.xml.dist` already makes for `tests/` and `bin/`.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Turns a signed envelope into a Manifest, or into a reason it was refused.
 *
 * The envelope is deliberately not "a JSON object with a signature field beside
 * the data". Verifying that shape means re-encoding the data to recover the
 * bytes that were signed, and any disagreement about key order, unicode
 * escaping or float formatting between the signer and the verifier becomes
 * either a false rejection or, worse, a signature that covers something other
 * than what was parsed. Instead the payload travels base64-encoded:
 *
 *     { "schema": 1, "payload": "<base64 of the manifest JSON bytes>",
 *       "signature": "<base64 of the 64-byte detached Ed25519 signature>" }
 *
 * so the bytes verified and the bytes parsed are provably the same bytes.
 */
final class Verifier {

	/**
	 * Envelope format this verifier understands.
	 */
	public const SCHEMA = 1;

	/**
	 * Constructor.
	 *
	 * @param string $public_key Base64 Ed25519 public key. Empty means "trust nothing".
	 * @param string $host       The update host this build trusts (bare lowercase hostname).
	 * @param string $tenant     The namespace the package URL must live under.
	 */
	public function __construct(
		private readonly string $public_key,
		private readonly string $host,
		private readonly string $tenant
	) {}

	/**
	 * Verify a signed envelope and parse the manifest inside it.
	 *
	 * @param string $envelope_json Raw response body, exactly as received.
	 * @return Manifest
	 *
	 * @throws ManifestError If anything at all is wrong. There is no partial success.
	 */
	public function open( string $envelope_json ): Manifest {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			throw new ManifestError( 'libsodium is unavailable, so no update can be verified.' );
		}

		$key = $this->decode_base64( $this->public_key, 'public key' );

		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			throw new ManifestError(
				'No usable update signing key is compiled into this build.',
				array( 'key_bytes' => strlen( $key ) )
			);
		}

		$envelope = json_decode( $envelope_json, true );

		if ( ! is_array( $envelope ) ) {
			throw new ManifestError( 'Update manifest is not JSON.' );
		}

		if ( ( $envelope['schema'] ?? null ) !== self::SCHEMA ) {
			throw new ManifestError(
				'Update manifest uses an unsupported envelope schema.',
				array( 'schema' => $envelope['schema'] ?? null )
			);
		}

		$payload_b64   = $envelope['payload'] ?? null;
		$signature_b64 = $envelope['signature'] ?? null;

		if ( ! is_string( $payload_b64 ) || ! is_string( $signature_b64 ) ) {
			throw new ManifestError( 'Update manifest envelope is missing payload or signature.' );
		}

		$payload   = $this->decode_base64( $payload_b64, 'payload' );
		$signature = $this->decode_base64( $signature_b64, 'signature' );

		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			throw new ManifestError(
				'Update manifest signature is the wrong length.',
				array( 'signature_bytes' => strlen( $signature ) )
			);
		}

		if ( ! sodium_crypto_sign_verify_detached( $signature, $payload, $key ) ) {
			throw new ManifestError( 'Update manifest signature does not verify.' );
		}

		return Manifest::from_array( json_decode( $payload, true ), $this->host, $this->tenant );
	}

	/**
	 * Strict base64 decode. Anything sloppy is treated as hostile.
	 *
	 * @param string $value What to decode.
	 * @param string $label What it is, for the log line.
	 * @return string
	 *
	 * @throws ManifestError If the value is not strict base64.
	 */
	private function decode_base64( string $value, string $label ): string {
		/*
		 * Strict mode: base64_decode() otherwise silently discards anything that
		 * is not in the alphabet, so a signature with a byte flipped to "!" would
		 * decode to a *different* valid signature rather than failing. The
		 * obfuscation warning is about hiding code in base64; this is the
		 * transport for a detached Ed25519 signature.
		 */
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $value, true );

		if ( false === $decoded ) {
			throw new ManifestError( 'Update manifest ' . $label . ' is not valid base64.' );
		}

		return $decoded;
	}
}
