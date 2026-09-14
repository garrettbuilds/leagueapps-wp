<?php
/**
 * Returns a canned SourceResult, or throws.
 *
 * No test in the unit suite touches the network. A test that needs a 503 gets
 * one on demand, which is the only reliable way to assert what happens on a 503.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Support;

use LeagueAppsWP\Contracts\ClientInterface;
use LeagueAppsWP\Domain\SourceResult;

final class FakeClient implements ClientInterface {

	private int $calls = 0;

	public function __construct(
		private readonly SourceResult|\Throwable $registrations,
		private readonly SourceResult|\Throwable|null $programs = null,
	) {}

	public function registrations( int $site_id, int $since_ms = 0 ): SourceResult {
		++$this->calls;
		if ( $this->registrations instanceof \Throwable ) {
			throw $this->registrations;
		}
		return $this->registrations;
	}

	public function programs( int $site_id, int $since_ms = 0 ): SourceResult {
		++$this->calls;
		$programs = $this->programs ?? SourceResult::exhausted( array(), 1 );
		if ( $programs instanceof \Throwable ) {
			throw $programs;
		}
		return $programs;
	}

	public function calls(): int {
		return $this->calls;
	}
}
