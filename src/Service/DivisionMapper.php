<?php
/**
 * Works out which division a registration belongs to.
 *
 * LeagueApps supports two ways of organising divisions and a Site may use
 * either, so this handles both, in order of authority:
 *
 *   1. The `division` field, when divisions live INSIDE one program. Explicit,
 *      set by the organiser, always wins.
 *   2. The program name, when there is a SEPARATE PROGRAM per division. This is
 *      inference, and is only used when the field is empty.
 *
 * Never assume one model. Measured across two live Sites the field is populated
 * 0% and 11% of the time because both use separate programs; a Site configured
 * with in-program divisions will be the opposite. Run `wp leagueapps discover`
 * against a Site before configuring it.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Service;

use LeagueAppsWP\Domain\DivisionMap;
use LeagueAppsWP\Domain\DivisionMatch;

final class DivisionMapper {

	public function __construct(
		private readonly DivisionMap $map,
	) {}

	public function map( string $program_name, string $division_field = '' ): DivisionMatch {
		$explicit = trim( $division_field );

		if ( '' !== $explicit ) {
			$key = $this->map->find_in( $explicit );
			if ( null !== $key ) {
				return DivisionMatch::known( $key, $this->map->label( $key ), $this->map->order( $key ), $explicit, 'division_field' );
			}

			// Set, but not a division we know. Do NOT fall back to the program
			// name: the organiser named something specific, and inferring past
			// it would publish the team in a division nobody put it in.
			return DivisionMatch::unrecognised( $explicit, 'division_field' );
		}

		$key = $this->map->find_in( $program_name );
		if ( null !== $key ) {
			return DivisionMatch::known( $key, $this->map->label( $key ), $this->map->order( $key ), $program_name, 'program_name' );
		}

		// No division value and nothing in the program name. A clinic, a free
		// agent list or a program that simply is not a division.
		return DivisionMatch::unassigned();
	}

	public function map_object(): DivisionMap {
		return $this->map;
	}
}
