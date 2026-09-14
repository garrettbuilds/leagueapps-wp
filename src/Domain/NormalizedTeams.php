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
		/**
		 * Every team-bearing row the reader saw and did NOT publish, with why.
		 *
		 * This exists because of one question an administrator always asks and
		 * could not previously answer: "the team is in LeagueApps, why is it not
		 * on the page?". The two most common answers - the program did not match
		 * the filter, and the registration is not holding a spot - used to drop
		 * the row with no record at all, so the honest reply was a shrug.
		 *
		 * @var array<string,array{team:string,reason:string,detail:string}>
		 */
		public readonly array $skipped = array(),
	) {}

	public function count(): int {
		return count( $this->teams );
	}
}
