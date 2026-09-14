<?php
/**
 * The durable team cache, backed by a custom table.
 *
 * Deactivation is a flag, not a DELETE. A team that withdraws and re-registers a
 * week later keeps its row, its first_seen_at and any display override an
 * operator set, which a delete-and-recreate would throw away.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

use LeagueAppsWP\Contracts\ClockInterface;
use LeagueAppsWP\Contracts\TeamRepositoryInterface;
use LeagueAppsWP\Domain\Team;

final class WpdbTeamRepository implements TeamRepositoryInterface {

	public function __construct(
		private readonly \wpdb $db,
		private readonly ClockInterface $clock,
	) {}

	public function active_for_event( string $event_key ): array {
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . Schema::teams_table() . ' WHERE event_key = %s AND is_active = 1',
				$event_key
			),
			ARRAY_A
		);

		$teams = array();

		foreach ( (array) $rows as $row ) {
			$id           = (int) $row['source_team_id'];
			$teams[ $id ] = new Team(
				event_key: (string) $row['event_key'],
				source_team_id: $id,
				name: (string) $row['team_name_source'],
				division_key: $row['division_key'] ?? null,
				division_label: $row['division_label'] ?? null,
				source_division_value: (string) $row['division_source'],
				roster_count: (int) $row['roster_count'],
				captain: (string) $row['captain'],
				program_id: null !== $row['program_id'] ? (int) $row['program_id'] : null,
				program_name: (string) $row['program_name'],
				display_name_override: (string) $row['display_name_override'],
			);
		}

		return $teams;
	}

	public function upsert( Team $team ): void {
		$now = $this->clock->now()->format( 'Y-m-d H:i:s' );

		$this->db->query(
			$this->db->prepare(
				'INSERT INTO ' . Schema::teams_table() . '
					(event_key, source_team_id, program_id, program_name, team_name_source,
					 display_name_override, division_key, division_label, division_source,
					 roster_count, captain, visible_hash, is_active,
					 first_seen_at, last_seen_at, synced_at)
				VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					program_id = VALUES(program_id),
					program_name = VALUES(program_name),
					team_name_source = VALUES(team_name_source),
					division_key = VALUES(division_key),
					division_label = VALUES(division_label),
					division_source = VALUES(division_source),
					roster_count = VALUES(roster_count),
					captain = VALUES(captain),
					visible_hash = VALUES(visible_hash),
					is_active = 1,
					last_seen_at = VALUES(last_seen_at),
					synced_at = VALUES(synced_at)',
				/*
				 * display_name_override and first_seen_at are deliberately NOT in
				 * the UPDATE list. An override is an operator's decision and a
				 * sync must never revert it; first_seen_at is when we first saw
				 * this team, which does not change because we saw it again.
				 */
				$team->event_key,
				$team->source_team_id,
				$team->program_id,
				$team->program_name,
				$team->name,
				$team->display_name_override,
				$team->division_key,
				$team->division_label,
				$team->source_division_value,
				$team->roster_count,
				$team->captain,
				$team->visible_hash(),
				$now,
				$now,
				$now
			)
		);
	}

	public function deactivate( string $event_key, int $source_team_id ): void {
		$this->db->update(
			Schema::teams_table(),
			array( 'is_active' => 0, 'synced_at' => $this->clock->now()->format( 'Y-m-d H:i:s' ) ),
			array( 'event_key' => $event_key, 'source_team_id' => $source_team_id ),
			array( '%d', '%s' ),
			array( '%s', '%d' )
		);
	}

	public function record_run( array $run ): void {
		$summary = (array) ( $run['summary'] ?? array() );
		$meta    = (array) ( $run['source_meta'] ?? array() );

		$this->db->insert( Schema::runs_table(), array(
			'run_id'          => (string) ( $run['run_id'] ?? '' ),
			'event_key'       => (string) ( $run['event_key'] ?? '' ),
			'mode'            => (string) ( $run['mode'] ?? 'apply' ),
			'status'          => (string) ( $run['status'] ?? '' ),
			'source_hash'     => (string) ( $run['source_hash'] ?? '' ),
			'rows_read'       => (int) ( $meta['rows_read'] ?? 0 ),
			'pages_read'      => (int) ( $meta['pages_read'] ?? 0 ),
			'source_complete' => (int) ( $meta['source_complete'] ?? 0 ),
			'creates'         => (int) ( $summary['create'] ?? 0 ),
			'updates'         => (int) ( $summary['update'] ?? 0 ),
			'renames'         => (int) ( $summary['rename'] ?? 0 ),
			'moves'           => (int) ( $summary['move_division'] ?? 0 ),
			'deactivations'   => (int) ( $summary['deactivate'] ?? 0 ),
			'held'            => (int) ( $summary['hold_for_review'] ?? 0 ),
			// Codes only. A run record is an audit trail, not a copy of the data.
			'error_codes'     => substr( implode( ',', (array) ( $run['error_codes'] ?? array() ) ), 0, 255 ),
			'warning_codes'   => substr( implode( ',', (array) ( $run['warning_codes'] ?? array() ) ), 0, 255 ),
			'plugin_version'  => defined( 'LAWP_VERSION' ) ? LAWP_VERSION : '',
			'started_at'      => (string) ( $run['planned_at'] ?? $this->clock->now()->format( 'Y-m-d H:i:s' ) ),
			'finished_at'     => $this->clock->now()->format( 'Y-m-d H:i:s' ),
		) );
	}

	public function begin(): void {
		$this->db->query( 'START TRANSACTION' );
	}

	public function commit(): void {
		$this->db->query( 'COMMIT' );
	}

	public function rollback(): void {
		$this->db->query( 'ROLLBACK' );
	}
}
