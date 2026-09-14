<?php
/**
 * Turns registrations into a teams-by-division list.
 *
 * There is no teams endpoint. Every registration carries the team it belongs to,
 * so a team list is a group-by on teamId.
 *
 * Group on the ID, never the name: "RIVERSIDE RANGERS" and "Riverside Rangers" are
 * one team, and grouping by name splits them.
 */

defined( 'ABSPATH' ) || defined( 'WP_CLI' ) || exit;

final class LAWP_Teams {

	/**
	 * @param array $rows      Registration rows, already reduced by LAWP_Fields.
	 * @param array $args      program_filter: substring the programName must contain.
	 *                         min_roster:     hide teams below this many registrations.
	 * @return array division key => array of teams
	 */
	public static function group( array $rows, array $args = array() ) {
		$filter = isset( $args['program_filter'] ) ? strtolower( (string) $args['program_filter'] ) : '';
		$min    = isset( $args['min_roster'] ) ? max( 1, (int) $args['min_roster'] ) : 1;

		$teams = array();

		foreach ( $rows as $r ) {
			$program = (string) ( $r['programName'] ?? '' );
			$name    = trim( (string) ( $r['team'] ?? '' ) );
			$id      = (string) ( $r['teamId'] ?? '' );

			if ( '' === $name || '' === $id || '' === $program ) { continue; }
			if ( '' !== $filter && false === strpos( strtolower( $program ), $filter ) ) { continue; }

			// Only registrations that actually hold a spot. A pending or
			// waitlisted entry is not a team in the tournament.
			if ( 'SPOT_RESERVED' !== strtoupper( (string) ( $r['registrationStatus'] ?? '' ) ) ) { continue; }

			$division = LAWP_Divisions::detect( $program );
			if ( ! $division ) { continue; }

			$key = $division['key'] . '|' . $id;

			if ( ! isset( $teams[ $key ] ) ) {
				$teams[ $key ] = array(
					'team_id'  => $id,
					'name'     => $name,
					'division' => $division['key'],
					'program'  => $program,
					'count'    => 0,
					'captain'  => '',
				);
			}

			$teams[ $key ]['count']++;

			// Longest observed name wins, which beats first-seen when casing or
			// spacing varies between rows for the same team.
			if ( strlen( $name ) > strlen( $teams[ $key ]['name'] ) ) {
				$teams[ $key ]['name'] = $name;
			}

			if ( 'CAPTAIN' === strtoupper( (string) ( $r['role'] ?? '' ) ) && '' === $teams[ $key ]['captain'] ) {
				$who = trim( ( $r['firstName'] ?? '' ) . ' ' . ( $r['lastName'] ?? '' ) );
				if ( '' !== $who ) { $teams[ $key ]['captain'] = $who; }
			}
		}

		$out = array();
		foreach ( $teams as $t ) {
			if ( $t['count'] < $min ) { continue; }
			$out[ $t['division'] ][] = $t;
		}

		foreach ( $out as $k => $list ) {
			usort( $list, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
			$out[ $k ] = $list;
		}

		$ordered = array();
		foreach ( LAWP_Divisions::order_keys( array_keys( $out ) ) as $k ) { $ordered[ $k ] = $out[ $k ]; }
		return $ordered;
	}
}
