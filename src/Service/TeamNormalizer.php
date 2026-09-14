<?php
/**
 * Turns registration rows into a list of teams.
 *
 * There is no teams endpoint on this API. Every registration carries the team it
 * belongs to, so a team list is a group-by on teamId. Grouping on the ID and
 * never the name is the whole correctness argument: "RIVERSIDE RANGERS" and
 * "Riverside Rangers" are one team, and grouping by name splits them into two.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Service;

use LeagueAppsWP\Domain\DivisionMatch;
use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\FieldPolicy;
use LeagueAppsWP\Domain\NormalizedTeams;
use LeagueAppsWP\Domain\Team;
use LeagueAppsWP\Domain\ValidationResult;

final class TeamNormalizer {

	/** A team name longer than this is a data problem, not a name. */
	private const MAX_NAME_LENGTH = 255;

	public function __construct(
		private readonly DivisionMapper $divisions,
	) {}

	public function normalize( array $rows, EventConfig $config ): NormalizedTeams {
		$validation = new ValidationResult();
		$grouped    = array();
		$excluded    = array();
		$seen_divisions = array();

		foreach ( $rows as $raw ) {
			$row = FieldPolicy::reduce( (array) $raw );

			$program_id   = isset( $row['programId'] ) ? (int) $row['programId'] : 0;
			$program_name = (string) ( $row['programName'] ?? '' );

			if ( ! $this->in_scope( $config, $program_id, $program_name ) ) {
				continue;
			}

			// Only registrations that actually hold a spot. A pending or
			// waitlisted entry is not a team in the event yet, and publishing
			// one tells a visitor something untrue.
			if ( 'SPOT_RESERVED' !== strtoupper( (string) ( $row['registrationStatus'] ?? '' ) ) ) {
				continue;
			}

			$team_id = $this->team_id( $row );
			$name    = $this->clean_name( (string) ( $row['team'] ?? '' ) );

			if ( null === $team_id ) {
				$excluded[] = array( 'reason' => 'MISSING_TEAM_ID', 'row' => $this->safe_row( $row ) );
				continue;
			}

			if ( '' === $name ) {
				$excluded[] = array( 'reason' => 'MISSING_TEAM_NAME', 'row' => $this->safe_row( $row ) );
				continue;
			}

			if ( ! isset( $grouped[ $team_id ] ) ) {
				$grouped[ $team_id ] = array(
					'name'         => $name,
					'program_id'   => $program_id,
					'program_name' => $program_name,
					'count'        => 0,
					'captain'      => '',
					'division'     => null,
				);
			}

			++$grouped[ $team_id ]['count'];

			// Longest observed name wins, which beats first-seen when casing or
			// spacing varies between rows for the same team.
			if ( strlen( $name ) > strlen( $grouped[ $team_id ]['name'] ) ) {
				$grouped[ $team_id ]['name'] = $name;
			}

			if ( 'CAPTAIN' === strtoupper( (string) ( $row['role'] ?? '' ) ) && '' === $grouped[ $team_id ]['captain'] ) {
				$who = $this->clean_name( trim( ( $row['firstName'] ?? '' ) . ' ' . ( $row['lastName'] ?? '' ) ) );
				if ( '' !== $who ) {
					$grouped[ $team_id ]['captain'] = $who;
				}
			}

			$match = $this->divisions->map( $program_name, (string) ( $row['division'] ?? '' ) );

			if ( null === $grouped[ $team_id ]['division'] ) {
				$grouped[ $team_id ]['division'] = $match;
			} elseif ( $this->disagrees( $grouped[ $team_id ]['division'], $match ) ) {
				// Two rows for one team naming different divisions. Keep the
				// first and say so, rather than letting row order decide.
				$validation->warn(
					'DIVISION_CONFLICT',
					sprintf( 'Team %d has registrations in more than one division.', $team_id ),
					array( 'team_id' => $team_id )
				);
			}
		}

		$teams = array();
		$held  = array();

		foreach ( $grouped as $team_id => $data ) {
			/** @var DivisionMatch $match */
			$match = $data['division'] ?? DivisionMatch::unassigned();

			$this->record_division( $seen_divisions, $match );

			$team = new Team(
				event_key: $config->event_key,
				source_team_id: $team_id,
				name: $data['name'],
				division_key: $match->key,
				division_label: $match->label,
				source_division_value: $match->source_value,
				roster_count: $data['count'],
				captain: $config->show_captain ? $data['captain'] : '',
				program_id: $data['program_id'],
				program_name: $data['program_name'],
			);

			if ( $data['count'] < $config->min_roster ) {
				$held[] = array( 'team' => $team, 'reason' => 'BELOW_MIN_ROSTER', 'source_value' => (string) $data['count'] );
				continue;
			}

			if ( ! $match->is_known() ) {
				$reason = DivisionMatch::UNRECOGNISED === $match->outcome ? 'UNKNOWN_DIVISION' : 'DIVISION_UNASSIGNED';
				$held[] = array( 'team' => $team, 'reason' => $reason, 'source_value' => $match->source_value );
				continue;
			}

			if ( ! $this->divisions->map_object()->is_visible( (string) $match->key ) ) {
				$held[] = array( 'team' => $team, 'reason' => 'DIVISION_HIDDEN', 'source_value' => (string) $match->key );
				continue;
			}

			$teams[ $team_id ] = $team;
		}

		ksort( $teams );

		return new NormalizedTeams( $teams, $held, $excluded, $seen_divisions, $validation );
	}

	private function in_scope( EventConfig $config, int $program_id, string $program_name ): bool {
		if ( array() !== $config->program_ids && ! in_array( $program_id, $config->program_ids, true ) ) {
			return false;
		}

		if ( '' !== $config->program_filter
			&& false === stripos( $program_name, $config->program_filter ) ) {
			return false;
		}

		return true;
	}

	/**
	 * A team id must be a positive integer.
	 *
	 * Rejected rather than cast, because (int) "abc" is 0 and (int) "12x" is 12.
	 * A cast turns a malformed id into a plausible one and merges unrelated teams.
	 */
	private function team_id( array $row ): ?int {
		$raw = $row['teamId'] ?? null;

		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}

		if ( is_string( $raw ) && 1 === preg_match( '/^[1-9][0-9]*$/', trim( $raw ) ) ) {
			return (int) trim( $raw );
		}

		return null;
	}

	/** Strip control characters, collapse whitespace, cap the length. */
	private function clean_name( string $name ): string {
		$clean = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $name );
		$clean = (string) preg_replace( '/\s+/u', ' ', $clean );
		$clean = trim( $clean );

		if ( strlen( $clean ) > self::MAX_NAME_LENGTH ) {
			$clean = rtrim( mb_strcut( $clean, 0, self::MAX_NAME_LENGTH ) );
		}

		return $clean;
	}

	private function disagrees( DivisionMatch $a, DivisionMatch $b ): bool {
		return $a->is_known() && $b->is_known() && $a->key !== $b->key;
	}

	private function record_division( array &$report, DivisionMatch $match ): void {
		$label = '' !== $match->source_value ? $match->source_value : '(unassigned)';

		if ( ! isset( $report[ $label ] ) ) {
			$report[ $label ] = array( 'key' => $match->key, 'outcome' => $match->outcome, 'teams' => 0, 'matched_on' => $match->matched_on );
		}

		++$report[ $label ]['teams'];
	}

	/** An excluded row is reported for triage, so it carries ids and nothing else. */
	private function safe_row( array $row ): array {
		return array(
			'programId' => $row['programId'] ?? null,
			'teamId'    => $row['teamId'] ?? null,
		);
	}
}
