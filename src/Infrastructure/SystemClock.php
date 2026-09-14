<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

use LeagueAppsWP\Contracts\ClockInterface;

final class SystemClock implements ClockInterface {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
