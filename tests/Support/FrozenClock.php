<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Support;

use LeagueAppsWP\Contracts\ClockInterface;

final class FrozenClock implements ClockInterface {

	private \DateTimeImmutable $now;

	public function __construct( string $when = '2026-09-14T15:15:00+00:00' ) {
		$this->now = new \DateTimeImmutable( $when );
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	public function advance( string $interval ): void {
		$this->now = $this->now->add( new \DateInterval( $interval ) );
	}
}
