<?php
/**
 * Everything one configured event needs, validated before it gets here.
 *
 * "Event" is this plugin's word, not LeagueApps'. It means one public page fed
 * by one source selection: a tournament, a season, or a set of programs. It is
 * named separately because LeagueApps has no single object that covers all
 * three, and pretending it does is how the wrong year's tournament ends up on
 * the page.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class EventConfig {

	/** Unknown-division policies, in order of strictness. */
	public const HOLD_FOR_REVIEW = 'hold_for_review';
	public const SHOW_PENDING    = 'show_pending';
	public const HIDE            = 'hide';
	public const FAIL_SYNC       = 'fail_sync';

	public function __construct(
		public readonly string $event_key,
		public readonly int $site_id,
		public readonly DivisionMap $divisions,
		/** Programs to include. Empty means every program the source returns. */
		public readonly array $program_ids = array(),
		/** Case-insensitive substring a program name must contain. Empty means no filter. */
		public readonly string $program_filter = '',
		public readonly string $display_title = '',
		public readonly ?int $page_id = null,
		public readonly string $unknown_division_policy = self::HOLD_FOR_REVIEW,
		/**
		 * Circuit breakers. A source that suddenly reports 8 teams where the
		 * cache holds 84 is far more likely to be a wrong program or a partial
		 * response than 76 teams withdrawing.
		 */
		public readonly int $max_deactivation_count = 10,
		public readonly int $max_deactivation_percent = 20,
		/** Hide a team with fewer registrations than this. 1 keeps everything. */
		public readonly int $min_roster = 1,
		/**
		 * Only publish a team whose registration fee is settled.
		 *
		 * Registration status alone is not proof of this. A spot can be reserved
		 * before a payment clears, and on the event this was built for the
		 * correlation held on the day it was checked and was never a guarantee.
		 */
		public readonly bool $require_payment = false,
		/**
		 * What counts as settled. More than one value, because "paid" is not the
		 * only way a fee stops being owed: a team may be covered by its league,
		 * or waived entirely, and both are settled as far as a public list goes.
		 */
		public readonly array $accepted_payment_statuses = array( 'PAID', 'NA_TEAM_PAYS', 'NA_FREE', 'COMPED' ),
		/**
		 * Tidy a team name that is entirely in capitals.
		 *
		 * OFF, because it cannot be done safely. "SWAMP DONKEYS" should become
		 * "Swamp Donkeys" and "STL ARCH NEMESIS" should not become "Stl Arch
		 * Nemesis", and nothing in the string distinguishes them. Person names
		 * are always tidied; they carry no acronyms worth protecting.
		 */
		public readonly bool $normalize_team_names = false,
		public readonly bool $show_roster_count = false,
		/**
		 * Name the team's manager publicly.
		 *
		 * A team credit is ordinary public information, and a league may well
		 * want it. Kept as a switch rather than a decision this plugin makes,
		 * because whether to publish a volunteer's name is the league's call.
		 */
		public readonly bool $show_captain = false,
		/** Show where the team travels from. Read the note on FieldPolicy first. */
		public readonly bool $show_location = false,
		/** Suburb-to-metro lookup. Empty means show the city exactly as written. */
		public readonly ?MetroMap $metros = null,
		public readonly string $timezone = 'UTC',
	) {}

	public function policy_is( string $policy ): bool {
		return $this->unknown_division_policy === $policy;
	}

	public static function policies(): array {
		return array( self::HOLD_FOR_REVIEW, self::SHOW_PENDING, self::HIDE, self::FAIL_SYNC );
	}
}
