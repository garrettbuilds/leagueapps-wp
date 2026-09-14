<?php
/**
 * The output of normalisation: teams we can publish, teams we cannot, and why.
 *
 * Held and excluded teams are carried rather than dropped. A team missing from
 * the page with no record of why is the failure mode this plugin exists to
 * avoid; an operator needs to see "2 held for review" and which two.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class NormalizedTeams {

	public function __construct(
		/** @var array<int,Team> keyed by source_team_id. Publishable. */
		public readonly array $teams,
		/** @var array<int,array{team:Team,reason:string,source_value:string}> */
		public readonly array $held,
		/** @var array<int,array{reason:string,row:array}> */
		public readonly array $excluded,
		/** source division value => ['key'=>?string,'teams'=>int,'outcome'=>string] */
		public readonly array $division_report,
		public readonly ValidationResult $validation,
	) {}

	public function count(): int {
		return count( $this->teams );
	}
}
