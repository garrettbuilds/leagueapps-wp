<?php
/**
 * Time, injected.
 *
 * Lock expiry, freshness thresholds and retry deferral are all time arithmetic,
 * and testing them against a real clock means either sleeping or asserting
 * nothing. Nothing in src/ calls time() directly.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Contracts;

interface ClockInterface {

	public function now(): \DateTimeImmutable;
}
