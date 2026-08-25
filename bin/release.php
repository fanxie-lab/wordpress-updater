<?php
/**
 * Release tooling: keys, ZIPs and signed manifests.
 *
 * Usage:
 *   php bin/release.php keygen [--write-to=FILE]
 *   php bin/release.php zip --source=DIR --slug=NAME --out=FILE
 *   php bin/release.php manifest --type=theme|plugin --slug=NAME --version=X.Y.Z \
 *                                 --package=URL [--url=URL] [--requires=6.6] \
 *                                 [--requires-php=8.4] [--tested=7.1] \
 *                                 [--key-env=NAME] --out=FILE
 *   php bin/release.php verify --manifest=FILE --host=HOST --namespace=NS \
 *                               (--key=BASE64 | --key-file=FILE)
 *
 * `manifest` reads the base64 Ed25519 secret key from the environment variable
 * named by `--key-env` (default `WP_UPDATE_SIGNING_KEY`) and never accepts it
 * as an argument, because arguments end up in `ps` output and in CI logs.
 *
 * `verify` requires `--host` and `--namespace` because the manifest's package
 * URL is checked against them (Manifest::from_array()) exactly as a site would
 * check it — the public key alone is not enough to reproduce that check.
 * `--key-file` greps the `COMPILED` constant out of a PHP source file, so CI
 * can verify against the key the client build actually ships, without
 * duplicating it as a separate secret.
 *
 * This is build tooling, not plugin code: it runs on a developer's machine and
 * in GitHub Actions, never inside WordPress. It reuses the client library's own
 * Verifier so that "the release we published" and "what the site will accept"
 * are checked by the same code rather than by two implementations that agree
 * until they don't.
 *
 * @package FanxieLab\WpUpdates
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates\Build;

/*
 * WordPress is not loaded here and never will be, so `wp_json_encode()`,
 * `WP_Filesystem` and the rest of the alternatives WPCS wants do not exist to
 * call. And base64 is the transport for an Ed25519 key and signature, not a way
 * of hiding code — the thing the obfuscation sniff is looking for.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

use FanxieLab\WpUpdates\ManifestError;
use FanxieLab\WpUpdates\Verifier;
use Throwable;
use ZipArchive;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$fx_release_root = dirname( __DIR__ );

foreach ( array( $fx_release_root . '/vendor/autoload.php', dirname( __DIR__, 3 ) . '/autoload.php' ) as $fx_autoload ) {
	if ( is_file( $fx_autoload ) ) {
		require_once $fx_autoload;
		break;
	}
}

/**
 * Write a line to a stream. WordPress is not loaded, so neither is WP_Filesystem.
 *
 * @param resource $stream  Open stream.
 * @param string   $message Message to write.
 * @return void
 */
function say( $stream, string $message ): void {
	fwrite( $stream, $message . PHP_EOL );
}

/**
 * Abort with a message on stderr.
 *
 * @param string $message Why we are stopping.
 * @return never
 */
function fail( string $message ): never {
	say( STDERR, 'release: ' . $message );
	exit( 1 );
}

/**
 * Parse `--key=value` and `--flag` arguments.
 *
 * @param string[] $argv Raw arguments, excluding the script name and subcommand.
 * @return array<string, string>
 */
function options( array $argv ): array {
	$options = array();

	foreach ( $argv as $argument ) {
		if ( ! str_starts_with( $argument, '--' ) ) {
			continue;
		}

		$argument = substr( $argument, 2 );
		$split    = strpos( $argument, '=' );

		if ( false === $split ) {
			$options[ $argument ] = '1';
			continue;
		}

		$options[ substr( $argument, 0, $split ) ] = substr( $argument, $split + 1 );
	}

	return $options;
}

/**
 * Read a required option, or abort.
 *
 * @param array<string, string> $options Parsed options.
 * @param string                $name    Option name, without dashes.
 * @return string
 */
function required( array $options, string $name ): string {
	$value = $options[ $name ] ?? '';

	if ( '' === trim( $value ) ) {
		fail( 'missing required option --' . $name );
	}

	return trim( $value );
}

/**
 * Generate an Ed25519 signing keypair.
 *
 * @param array<string, string> $options Parsed options.
 * @return void
 */
function keygen( array $options ): void {
	$pair   = sodium_crypto_sign_keypair();
	$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
	$secret = base64_encode( sodium_crypto_sign_secretkey( $pair ) );

	if ( isset( $options['write-to'] ) ) {
		$key_file = $options['write-to'];
		$source   = file_get_contents( $key_file );

		if ( false === $source ) {
			fail( 'could not read ' . $key_file );
		}

		$patched = preg_replace(
			"/public const COMPILED = '[^']*';/",
			"public const COMPILED = '" . $public . "';",
			$source,
			1,
			$count
		);

		if ( ! is_string( $patched ) || 1 !== $count ) {
			fail( 'could not find the COMPILED constant in ' . $key_file );
		}

		file_put_contents( $key_file, $patched );
		say( STDOUT, 'Wrote the public key into ' . $key_file . '. Commit that change.' );
	} else {
		say( STDOUT, 'Public key (paste into the COMPILED constant, or re-run with --write-to=FILE):' );
		say( STDOUT, '  ' . $public );
	}

	say( STDOUT, '' );
	say( STDOUT, 'Secret key — store as the GitHub Actions secret {NAMESPACE}_UPDATE_SIGNING_KEY' );
	say( STDOUT, '(e.g. STILOTEX_UPDATE_SIGNING_KEY) and then delete it from your scrollback. It is' );
	say( STDOUT, 'not written to disk and cannot be recovered:' );
	say( STDOUT, '  ' . $secret );

	sodium_memzero( $pair );
}

/**
 * Build a ZIP whose single top-level directory is the package slug.
 *
 * WordPress installs an update by unpacking the archive into wp-content and
 * expecting exactly one directory inside. Getting that wrong produces a
 * plugin installed at `wp-content/plugins/fx-demo-1.2.3/`, silently beside the
 * one that is active — which is why this is a build step rather than a
 * `zip -r` in a workflow.
 *
 * @param array<string, string> $options Parsed options.
 * @return void
 */
function zip( array $options ): void {
	$source = rtrim( required( $options, 'source' ), '/' );
	$slug   = required( $options, 'slug' );
	$out    = required( $options, 'out' );

	if ( ! is_dir( $source ) ) {
		fail( 'source directory does not exist: ' . $source );
	}

	if ( file_exists( $out ) ) {
		unlink( $out );
	}

	$archive = new ZipArchive();

	if ( true !== $archive->open( $out, ZipArchive::CREATE ) ) {
		fail( 'could not create ' . $out );
	}

	$files = array();
	$walk  = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $walk as $item ) {
		if ( ! $item instanceof \SplFileInfo || ! $item->isFile() ) {
			continue;
		}

		$files[] = $item->getPathname();
	}

	// Deterministic order: two builds of the same tree should produce the same listing.
	sort( $files, SORT_STRING );

	foreach ( $files as $path ) {
		$archive->addFile( $path, $slug . '/' . ltrim( substr( $path, strlen( $source ) ), '/' ) );
	}

	$count = count( $files );

	if ( ! $archive->close() ) {
		fail( 'could not finish writing ' . $out );
	}

	say( STDOUT, sprintf( 'Wrote %s (%d files under %s/).', $out, $count, $slug ) );
}

/**
 * Emit a signed manifest envelope.
 *
 * @param array<string, string> $options Parsed options.
 * @return void
 */
function manifest( array $options ): void {
	$env_name   = $options['key-env'] ?? 'WP_UPDATE_SIGNING_KEY';
	$secret_b64 = getenv( $env_name );

	if ( ! is_string( $secret_b64 ) || '' === trim( $secret_b64 ) ) {
		fail( $env_name . ' is not set. It holds the base64 Ed25519 secret key.' );
	}

	$secret = base64_decode( trim( $secret_b64 ), true );

	if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
		fail( $env_name . ' is not a base64 Ed25519 secret key.' );
	}

	$payload = array(
		'type'         => required( $options, 'type' ),
		'slug'         => required( $options, 'slug' ),
		'version'      => required( $options, 'version' ),
		'package'      => required( $options, 'package' ),
		'url'          => $options['url'] ?? '',
		'requires'     => $options['requires'] ?? '',
		'requires_php' => $options['requires-php'] ?? '',
		'tested'       => $options['tested'] ?? '',
		'released'     => gmdate( 'c' ),
	);

	$payload_json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

	if ( false === $payload_json ) {
		fail( 'could not encode the manifest payload' );
	}

	$envelope = array(
		'schema'    => Verifier::SCHEMA,
		'payload'   => base64_encode( $payload_json ),
		'signature' => base64_encode( sodium_crypto_sign_detached( $payload_json, $secret ) ),
	);

	sodium_memzero( $secret );

	$envelope_json = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

	if ( false === $envelope_json ) {
		fail( 'could not encode the manifest envelope' );
	}

	$out = required( $options, 'out' );

	file_put_contents( $out, $envelope_json . PHP_EOL );

	say( STDOUT, 'Wrote ' . $out . '.' );
}

/**
 * Verify a manifest with the same code the site will use.
 *
 * @param array<string, string> $options Parsed options.
 * @return void
 */
function verify( array $options ): void {
	$path = required( $options, 'manifest' );

	if ( ! is_readable( $path ) ) {
		fail( 'cannot read ' . $path );
	}

	$body = file_get_contents( $path );

	if ( false === $body ) {
		fail( 'cannot read ' . $path );
	}

	$key = $options['key'] ?? '';

	if ( '' === $key && isset( $options['key-file'] ) ) {
		$key_source = file_get_contents( $options['key-file'] );
		if ( is_string( $key_source ) && 1 === preg_match( "/public const COMPILED = '([^']*)';/", $key_source, $m ) ) {
			$key = $m[1];
		}
	}

	if ( '' === $key ) {
		fail( 'no public key: pass --key=BASE64 or --key-file=FILE (a PHP file with a COMPILED constant).' );
	}

	try {
		$parsed = ( new Verifier( $key, required( $options, 'host' ), required( $options, 'namespace' ) ) )->open( $body );
	} catch ( ManifestError $error ) {
		fail( 'manifest rejected: ' . $error->getMessage() );
	}

	say(
		STDOUT,
		sprintf(
			'Manifest verifies: %s %s %s -> %s',
			$parsed->type->value,
			$parsed->slug,
			$parsed->version,
			$parsed->package
		)
	);
}

$fx_release_argv = $argv ?? array();
$fx_release_verb = $fx_release_argv[1] ?? '';
$fx_release_opts = options( array_slice( $fx_release_argv, 2 ) );

try {
	switch ( $fx_release_verb ) {
		case 'keygen':
			keygen( $fx_release_opts );
			break;
		case 'zip':
			zip( $fx_release_opts );
			break;
		case 'manifest':
			manifest( $fx_release_opts );
			break;
		case 'verify':
			verify( $fx_release_opts );
			break;
		default:
			say( STDERR, 'Usage: php bin/release.php <keygen|zip|manifest|verify> [options]' );
			say( STDERR, 'See the docblock at the top of this file.' );
			exit( 1 );
	}
} catch ( Throwable $fx_release_error ) {
	fail( $fx_release_error->getMessage() );
}
