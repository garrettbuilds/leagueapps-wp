<?php
/**
 * Wraps the real repository and throws on every write.
 *
 * Injected in dry-run mode. The planner already has no write path, so this is
 * not needed for correctness today: it is here so that the day somebody adds a
 * convenient little save() call inside the planning path, the test suite fails
 * with a stack trace pointing at the line, rather than a dry run quietly writing
 * to the public cache and nobody noticing for a season.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

use LeagueAppsWP\Contracts\TeamRepositoryInterface;
use LeagueAppsWP\Domain\Team;

final class ReadOnlyTeamRepository implements TeamRepositoryInterface {

	public function __construct(
		private readonly TeamRepositoryInterface $inner,
	) {}

	public function active_for_event( string $event_key ): array {
		return $this->inner->active_for_event( $event_key );
	}

	public function upsert( Team $team ): void {
		throw new \LogicException( 'A dry run attempted to write the team cache.' );
	}

	public function deactivate( string $event_key, int $source_team_id ): void {
		throw new \LogicException( 'A dry run attempted to deactivate a team.' );
	}

	public function record_run( array $run ): void {
		throw new \LogicException( 'A dry run attempted to write sync history.' );
	}

	/* Transaction control is a no-op rather than a throw: a caller may wrap a
	   read in one harmlessly, and throwing here would only make the guard
	   awkward to place. */
	public function begin(): void {}

	public function commit(): void {}

	public function rollback(): void {}
}
