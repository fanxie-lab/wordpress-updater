<?php
declare( strict_types=1 );

namespace Fanxie\WpUpdates\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Fanxie\WpUpdates\Log;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase {

	// Adds Mockery/Brain Monkey expectations (e.g. Actions\expectDone()) to the
	// PHPUnit assertion count, per Brain Monkey's own setup docs — without it,
	// a test whose only assertion is a hook expectation is flagged risky under
	// this project's beStrictAboutTestsThatDoNotTestAnything=true.
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_refusal_fires_the_prefixed_action_with_message_and_context(): void {
		Actions\expectDone( 'fxdemo_update_refused' )
			->once()
			->with( 'nope', array( 'why' => 'test' ) );

		( new Log( 'fxdemo' ) )->refused( 'nope', array( 'why' => 'test' ) );
	}

	public function test_two_prefixes_fire_two_distinct_actions(): void {
		Actions\expectDone( 'stilotex_update_refused' )->once();
		Actions\expectDone( 'fxdemo_update_refused' )->never();

		( new Log( 'stilotex' ) )->refused( 'nope' );
	}
}
