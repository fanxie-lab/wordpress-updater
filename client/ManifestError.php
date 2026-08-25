<?php
/**
 * Raised when a manifest cannot be trusted.
 *
 * @package FanxieLab\WpUpdates
 */

declare( strict_types=1 );

namespace FanxieLab\WpUpdates;

use RuntimeException;

/**
 * Every rejection reason for a manifest, funnelled through one type.
 *
 * There is deliberately no distinction between "malformed" and "forged": both
 * mean the same thing to the caller, which is that nothing gets offered. The
 * message is for the log, never for the screen.
 */
final class ManifestError extends RuntimeException {

	/**
	 * Build an error carrying structured context for the log.
	 *
	 * @param string               $message Human-readable reason.
	 * @param array<string, mixed> $context Structured detail for the log line.
	 */
	public function __construct( string $message, private readonly array $context = array() ) {
		parent::__construct( $message );
	}

	/**
	 * Structured detail for the log line.
	 *
	 * @return array<string, mixed>
	 */
	public function context(): array {
		return $this->context;
	}
}
