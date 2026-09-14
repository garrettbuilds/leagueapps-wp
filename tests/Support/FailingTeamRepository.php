<?php
/**
 * A repository whose writes fail, to prove the applier rolls back.
 *
 * A separate class rather than an anonymous subclass because InMemoryTeamRepository
 * is final, and it stays final: a test double that can be quietly extended is a
 * test double whose behaviour is no longer obvious from its name.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Support;

use LeagueAppsWP\Contracts\TeamRepositoryInterface;
use LeagueAppsWP\Domain\Team;

final class FailingTeamRepository implements TeamRepositoryInterface {

	private array $teams = array();
	private int $depth   = 0;

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
		throw new \RuntimeException( 'the database went away' );
	}

	public function deactivate( string $event_key, int $source_team_id ): void {
		throw new \RuntimeException( 'the database went away' );
	}

	public function record_run( array $run ): void {}

	public function begin(): void {
		++$this->depth;
	}

	public function commit(): void {
		--$this->depth;
	}

	public function rollback(): void {
		--$this->depth;
	}

	public function open_transactions(): int {
		return $this->depth;
	}
}
