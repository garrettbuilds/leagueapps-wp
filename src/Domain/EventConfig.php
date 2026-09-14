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
		public readonly bool $show_roster_count = false,
		public readonly bool $show_captain = false,
		public readonly string $timezone = 'UTC',
	) {}

	public function policy_is( string $policy ): bool {
		return $this->unknown_division_policy === $policy;
	}

	public static function policies(): array {
		return array( self::HOLD_FOR_REVIEW, self::SHOW_PENDING, self::HIDE, self::FAIL_SYNC );
	}
}
