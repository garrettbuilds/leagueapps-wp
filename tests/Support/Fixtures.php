<?php
/**
 * Named after behaviour, not after dates or production exports.
 *
 * Every team name, program name and Site id here is invented. Real ones were
 * scrubbed from this repository before its first commit and must not come back:
 * a fixture is committed, public, and permanent.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Support;

use LeagueAppsWP\Domain\DivisionMap;
use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\SourceResult;
use LeagueAppsWP\Domain\Team;
use LeagueAppsWP\Service\DivisionMapper;
use LeagueAppsWP\Service\SourceValidator;
use LeagueAppsWP\Service\SyncPlanner;
use LeagueAppsWP\Service\TeamNormalizer;

final class Fixtures {

	public const EVENT   = 'summer-classic-2026';
	public const SITE    = 1234;
	public const PROGRAM = 5678;

	public static function division_map(): DivisionMap {
		return new DivisionMap( require dirname( __DIR__, 2 ) . '/presets/letter-grades.php' );
	}

	public static function config( array $overrides = array() ): EventConfig {
		return new EventConfig(
			event_key: $overrides['event_key'] ?? self::EVENT,
			site_id: $overrides['site_id'] ?? self::SITE,
			divisions: $overrides['divisions'] ?? self::division_map(),
			program_ids: $overrides['program_ids'] ?? array(),
			program_filter: $overrides['program_filter'] ?? '',
			unknown_division_policy: $overrides['unknown_division_policy'] ?? EventConfig::HOLD_FOR_REVIEW,
			max_deactivation_count: $overrides['max_deactivation_count'] ?? 10,
			max_deactivation_percent: $overrides['max_deactivation_percent'] ?? 20,
			require_payment: $overrides['require_payment'] ?? false,
			accepted_payment_statuses: $overrides['accepted_payment_statuses'] ?? array( 'PAID', 'NA_TEAM_PAYS', 'NA_FREE', 'COMPED' ),
			normalize_team_names: $overrides['normalize_team_names'] ?? false,
			min_roster: $overrides['min_roster'] ?? 1,
			show_captain: $overrides['show_captain'] ?? false,
			show_location: $overrides['show_location'] ?? false,
			metros: $overrides['metros'] ?? new \LeagueAppsWP\Domain\MetroMap( require dirname( __DIR__, 2 ) . '/presets/us-metros.php' ),
		);
	}

	public static function planner(): SyncPlanner {
		return new SyncPlanner( new SourceValidator(), new TeamNormalizer( new DivisionMapper( self::division_map() ) ) );
	}

	public static function planner_with( DivisionMap $map ): SyncPlanner {
		return new SyncPlanner( new SourceValidator(), new TeamNormalizer( new DivisionMapper( $map ) ) );
	}

	/** One registration row, in the shape the export endpoint returns. */
	public static function row( array $overrides = array() ): array {
		return array_merge( array(
			'programId'          => self::PROGRAM,
			'programName'        => '2026 Summer Classic (C Division)',
			'programState'       => 'ACTIVE',
			'teamId'             => 84321,
			'team'               => 'Riverside Rangers',
			'division'           => '',
			'season'             => 'Summer 2026',
			'registrationStatus' => 'SPOT_RESERVED',
			'role'               => 'PLAYER',
			'isStaff'            => false,
			'firstName'          => 'Alex',
			'lastName'           => 'Rivera',
		), $overrides );
	}

	/** A complete read of one team with one registration. */
	public static function source_one_team(): SourceResult {
		return SourceResult::exhausted( array( self::row() ), 1 );
	}

	/**
	 * @param int $count How many teams, one registration each, spread over divisions.
	 */
	public static function source_teams( int $count, array $overrides = array() ): SourceResult {
		$divisions = array( 'A', 'B', 'C', 'D', 'E' );
		$rows      = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = self::row( array_merge( array(
				'teamId'      => 90000 + $i,
				'team'        => 'Test Team ' . ( $i + 1 ),
				'programName' => sprintf( '2026 Summer Classic (%s Division)', $divisions[ $i % 5 ] ),
			), $overrides ) );
		}

		return SourceResult::exhausted( $rows, 1 );
	}

	/** @return array<int,Team> keyed by source_team_id, as the repository returns. */
	public static function cached_teams( int $count ): array {
		$divisions = array( 'a', 'b', 'c', 'd', 'e' );
		$labels    = array( 'A Division', 'B Division', 'C Division', 'D Division', 'E Division' );
		$teams     = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$teams[ 90000 + $i ] = new Team(
				event_key: self::EVENT,
				source_team_id: 90000 + $i,
				name: 'Test Team ' . ( $i + 1 ),
				division_key: $divisions[ $i % 5 ],
				division_label: $labels[ $i % 5 ],
				roster_count: 1,
				program_id: self::PROGRAM,
			);
		}

		return $teams;
	}
}
