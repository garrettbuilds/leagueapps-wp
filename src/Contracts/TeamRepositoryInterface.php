<?php
/**
 * The public cache. The planner only ever reads through this; the applier writes.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Contracts;

use LeagueAppsWP\Domain\Team;

interface TeamRepositoryInterface {

	/** @return array<int,Team> keyed by source_team_id. */
	public function active_for_event( string $event_key ): array;

	public function upsert( Team $team ): void;

	public function deactivate( string $event_key, int $source_team_id ): void;

	public function record_run( array $run ): void;

	public function begin(): void;

	public function commit(): void;

	public function rollback(): void;
}
