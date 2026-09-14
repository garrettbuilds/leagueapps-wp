<?php
/**
 * A repository that counts its writes.
 *
 * write_count() is the assertion that matters for dry-run tests. Checking that
 * the stored teams are unchanged is weaker: a write that puts back the same value
 * is still a write, still bumps a timestamp, and still purges a page cache.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Support;

use LeagueAppsWP\Contracts\TeamRepositoryInterface;
use LeagueAppsWP\Domain\Team;

final class InMemoryTeamRepository implements TeamRepositoryInterface {

	private array $teams  = array();
	private array $runs   = array();
	private int $writes   = 0;
	private int $depth    = 0;

	/** @param Team[] $teams */
	public function __construct( array $teams = array() ) {
		foreach ( $teams as $team ) {
			$this->teams[ $team->event_key ][ $team->source_team_id ] = $team;
		}
	}

	public function active_for_event( string $event_key ): array {
		return $this->teams[ $event_key ] ?? array();
	}

	public function upsert( Team $team ): void {
		++$this->writes;
		$this->teams[ $team->event_key ][ $team->source_team_id ] = $team;
	}

	public function deactivate( string $event_key, int $source_team_id ): void {
		++$this->writes;
		unset( $this->teams[ $event_key ][ $source_team_id ] );
	}

	public function record_run( array $run ): void {
		++$this->writes;
		$this->runs[] = $run;
	}

	public function begin(): void {
		++$this->depth;
	}

	public function commit(): void {
		--$this->depth;
	}

	public function rollback(): void {
		--$this->depth;
	}

	public function write_count(): int {
		return $this->writes;
	}

	public function runs(): array {
		return $this->runs;
	}

	public function open_transactions(): int {
		return $this->depth;
	}
}
