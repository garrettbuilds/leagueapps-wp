<?php
/**
 * Works out how a Site is organised, by reading it.
 *
 * Run before configuring anything against a new Site. It reports which division
 * model the Site uses, which division values exist and how many teams are in
 * each, so an operator confirms a mapping instead of the plugin guessing and
 * being quietly wrong for a season.
 *
 * This exists because the first version of this plugin inferred divisions from
 * program names, which is correct for the two Sites it was written against and
 * wrong for any Site that uses divisions inside one program. Discovery is the
 * fix: ask the Site, do not assume.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Service;

use LeagueAppsWP\Domain\DivisionMap;

final class Discovery {

	public const MODEL_IN_PROGRAM      = 'divisions_inside_one_program';
	public const MODEL_PROGRAM_PER_DIV = 'separate_program_per_division';
	public const MODEL_MIXED           = 'mixed';
	public const MODEL_UNKNOWN         = 'no_divisions_found';

	/**
	 * @param array $registrations Rows already reduced by FieldPolicy.
	 * @param array $programs      Program rows.
	 */
	public function inspect( array $registrations, array $programs, ?DivisionMap $map = null ): array {
		$field_values   = array();
		$program_names  = array();
		$rows_with_field = 0;
		$teams_seen     = array();

		foreach ( $registrations as $row ) {
			$team = (string) ( $row['teamId'] ?? '' );
			if ( '' !== $team ) {
				$teams_seen[ $team ] = true;
			}

			$value = trim( (string) ( $row['division'] ?? '' ) );
			if ( '' !== $value ) {
				++$rows_with_field;
				$field_values[ $value ] = ( $field_values[ $value ] ?? 0 ) + 1;
			}

			$program = trim( (string) ( $row['programName'] ?? '' ) );
			if ( '' !== $program ) {
				$program_names[ $program ] = ( $program_names[ $program ] ?? 0 ) + 1;
			}
		}

		$total   = max( 1, count( $registrations ) );
		$percent = (int) round( $rows_with_field / $total * 100 );

		return array(
			'registrations'        => count( $registrations ),
			'teams'                => count( $teams_seen ),
			'programs'             => count( $programs ),
			'division_field_percent' => $percent,
			'division_field_values' => $this->sorted( $field_values ),
			'program_names'        => $this->sorted( $program_names ),
			'model'                => $this->model( $percent, $field_values, $program_names, $map ),
			'unmatched'            => null !== $map ? $this->unmatched( $field_values, $program_names, $map ) : array(),
		);
	}

	/**
	 * Which model is this Site using?
	 *
	 * The threshold is deliberately generous in both directions, and anything in
	 * between is reported as mixed rather than forced into one answer. A Site
	 * that runs both is a real thing: measured on two live Sites the field is
	 * populated 0% and 11% of the time, and 11% is not "uses in-program
	 * divisions" or "does not" - it is a Site that does both and needs a person
	 * to look.
	 */
	private function model( int $percent, array $field_values, array $program_names, ?DivisionMap $map ): string {
		if ( $percent >= 80 ) {
			return self::MODEL_IN_PROGRAM;
		}

		$program_hits = 0;
		if ( null !== $map ) {
			foreach ( array_keys( $program_names ) as $name ) {
				if ( null !== $map->find_in( (string) $name ) ) {
					++$program_hits;
				}
			}
		}

		if ( $percent <= 5 ) {
			return $program_hits > 0 ? self::MODEL_PROGRAM_PER_DIV : self::MODEL_UNKNOWN;
		}

		return self::MODEL_MIXED;
	}

	/** Values with teams behind them that no configured division claims. */
	private function unmatched( array $field_values, array $program_names, DivisionMap $map ): array {
		$out = array();

		foreach ( $field_values as $value => $count ) {
			if ( null === $map->find_in( (string) $value ) ) {
				$out['division_field'][ $value ] = $count;
			}
		}

		foreach ( $program_names as $name => $count ) {
			if ( null === $map->find_in( (string) $name ) ) {
				$out['program_name'][ $name ] = $count;
			}
		}

		return $out;
	}

	private function sorted( array $counts ): array {
		arsort( $counts );
		return $counts;
	}

	public function explain( array $report ): string {
		$out = array();

		$out[] = 'LeagueApps Site discovery';
		$out[] = sprintf( '  Registrations read: %d', $report['registrations'] );
		$out[] = sprintf( '  Distinct teams:     %d', $report['teams'] );
		$out[] = sprintf( '  Programs:           %d', $report['programs'] );
		$out[] = sprintf( '  division field set: %d%% of rows', $report['division_field_percent'] );
		$out[] = '';

		$out[] = 'Division model: ' . $report['model'];
		$out[] = '  ' . match ( $report['model'] ) {
			self::MODEL_IN_PROGRAM      => 'Divisions live inside programs. Map the division field values below.',
			self::MODEL_PROGRAM_PER_DIV => 'Each division is its own program. Map the program names below.',
			self::MODEL_MIXED           => 'Both, which is normal for a Site with years of history. Map both lists; the field wins where it is set.',
			default                     => 'No divisions found. This Site may not use them, or may name them in a way no mapping covers yet.',
		};
		$out[] = '';

		if ( array() !== $report['division_field_values'] ) {
			$out[] = 'division field values';
			foreach ( $report['division_field_values'] as $value => $count ) {
				$out[] = sprintf( '  %-40s %d row(s)', $value, $count );
			}
			$out[] = '';
		}

		if ( array() !== ( $report['unmatched'] ?? array() ) ) {
			$out[] = 'NOT MATCHED by the current division map';
			foreach ( $report['unmatched'] as $where => $values ) {
				foreach ( array_slice( $values, 0, 30, true ) as $value => $count ) {
					$out[] = sprintf( '  [%s] %-34s %d row(s)', $where, $value, $count );
				}
			}
			$out[] = '';
			$out[] = '  Teams in these are held, not published. Map them or hide them.';
		}

		return implode( "\n", $out );
	}
}
