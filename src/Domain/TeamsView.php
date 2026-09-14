<?php
/**
 * Everything the public renderer needs, and nothing else.
 *
 * Built once from the cache, then cached itself under a generation key. It holds
 * display strings rather than source rows, so nothing downstream has to decide
 * what a visitor is allowed to see: that decision was made upstream and is not
 * reachable from here.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class TeamsView {

	public function __construct(
		public readonly string $event_key,
		/** @var array<int,array{key:string,label:string,anchor:string,count:int,teams:array}> */
		public readonly array $divisions,
		public readonly int $total_teams,
		public readonly ?string $last_sync_at,
		public readonly int $generation,
		/** True when the last successful sync is older than the event expects. */
		public readonly bool $is_stale = false,
	) {}

	public function is_empty(): bool {
		return 0 === $this->total_teams;
	}

	public function never_synced(): bool {
		return null === $this->last_sync_at;
	}
}
