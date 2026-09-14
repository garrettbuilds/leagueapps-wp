<?php
/**
 * Works out how a Site is organised, by reading it.
 *
 * Run this once when installing against a new LeagueApps Site. It reports which
 * division model the Site uses, which Divisions exist, and which Programs the
 * plugin would skip, so an administrator confirms the mapping instead of the
 * plugin guessing and being quietly wrong.
 */

defined( 'ABSPATH' ) || defined( 'WP_CLI' ) || exit;

final class LAWP_Discover {

	/**
	 * @param array $registrations Rows already reduced by LAWP_Fields.
	 * @param array $programs      Raw Program rows.
	 */
	public static function inspect( array $registrations, array $programs ) {
		$with_field = 0;
		$field_values = array();

		foreach ( $registrations as $r ) {
			$d = trim( (string) ( $r['division'] ?? '' ) );
			if ( '' !== $d ) {
				$with_field++;
				$field_values[ $d ] = ( $field_values[ $d ] ?? 0 ) + 1;
			}
		}

		$total = max( 1, count( $registrations ) );
		$pct   = round( $with_field / $total * 100 );

		// Which model this Site uses. The threshold is deliberately low: if the
		// field is set at all, somebody is using it on purpose.
		$model = $pct >= 50 ? 'divisions_in_program' : ( $pct > 0 ? 'mixed' : 'program_per_division' );

		$matched = array();
		$skipped = array();
		foreach ( $programs as $p ) {
			$name = (string) ( $p['name'] ?? '' );
			if ( '' === $name ) { continue; }
			$hit = LAWP_Divisions::detect( $name, '' );
			if ( $hit ) {
				$matched[ $hit['key'] ][] = $name;
			} else {
				$skipped[] = $name;
			}
		}

		$unknown_fields = array();
		foreach ( array_keys( $field_values ) as $v ) {
			if ( ! LAWP_Divisions::detect( '', $v ) ) { $unknown_fields[] = $v; }
		}

		return array(
			'model'                => $model,
			'division_field_pct'   => $pct,
			'division_field_values'=> $field_values,
			'unknown_field_values' => $unknown_fields,
			'divisions_found'      => array_keys( $matched ),
			'programs_matched'     => array_sum( array_map( 'count', $matched ) ),
			'programs_skipped'     => $skipped,
		);
	}

	/** Human-readable summary for the CLI and the admin screen. */
	public static function explain( array $r ) {
		$lines = array();

		$model = array(
			'divisions_in_program' => 'Divisions inside one Program. The division field is authoritative.',
			'program_per_division' => 'A separate Program per Division. Division is read from the Program name.',
			'mixed'                => 'MIXED. Some Registrations carry a division field and most do not. Check the configuration.',
		);
		$lines[] = 'Model:    ' . ( $model[ $r['model'] ] ?? $r['model'] );
		$lines[] = sprintf( 'Division field set on %d%% of Registrations', $r['division_field_pct'] );
		$lines[] = '';
		$lines[] = sprintf( 'Divisions found: %s', $r['divisions_found'] ? implode( ', ', array_map( array( 'LAWP_Divisions', 'label' ), $r['divisions_found'] ) ) : 'none' );
		$lines[] = sprintf( 'Programs matched to a Division: %d', $r['programs_matched'] );
		$lines[] = sprintf( 'Programs skipped: %d', count( $r['programs_skipped'] ) );

		if ( $r['unknown_field_values'] ) {
			$lines[] = '';
			$lines[] = 'Division values this plugin does not recognise, which will be HELD rather than displayed:';
			foreach ( $r['unknown_field_values'] as $v ) { $lines[] = '  ' . $v; }
			$lines[] = 'Add them to class-lawp-divisions.php, or leave them held.';
		}

		if ( $r['programs_skipped'] ) {
			$lines[] = '';
			$lines[] = 'Skipped Programs (a sample). These are usually tournaments, tests or';
			$lines[] = 'post-season events rather than Divisions, and skipping is correct:';
			foreach ( array_slice( $r['programs_skipped'], 0, 8 ) as $p ) { $lines[] = '  ' . $p; }
		}

		return implode( "\n", $lines );
	}
}
