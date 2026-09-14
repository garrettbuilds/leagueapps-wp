<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\PlannedChange;
use LeagueAppsWP\Domain\SourceResult;
use LeagueAppsWP\Domain\SyncPlan;
use LeagueAppsWP\Domain\Team;
use LeagueAppsWP\Service\SyncPlanner;
use LeagueAppsWP\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class SyncPlannerTest extends TestCase {

	public function test_a_team_the_cache_has_never_seen_is_a_create(): void {
		$plan = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_one_team(), array() );

		self::assertSame( SyncPlan::PASS, $plan->status );
		self::assertSame( 1, $plan->count( PlannedChange::CREATE ) );
		self::assertTrue( $plan->safe_to_apply() );
	}

	public function test_an_unchanged_team_is_no_change_and_needs_no_work(): void {
		$existing = Fixtures::cached_teams( 5 );
		$plan     = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_teams( 5 ), $existing );

		self::assertSame( SyncPlan::NO_CHANGE, $plan->status );
		self::assertSame( 5, $plan->count( PlannedChange::NO_CHANGE ) );
		self::assertSame( array(), $plan->writes() );
		self::assertSame( 0, $plan->exit_code() );
	}

	public function test_a_renamed_team_is_a_rename_not_a_create_and_a_deactivate(): void {
		$existing = Fixtures::cached_teams( 1 );
		$source   = Fixtures::source_teams( 1, array( 'team' => 'Test Team 1 Renamed' ) );

		$plan = Fixtures::planner()->plan( Fixtures::config(), $source, $existing );

		self::assertSame( 1, $plan->count( PlannedChange::RENAME ) );
		self::assertSame( 0, $plan->count( PlannedChange::CREATE ) );
		self::assertSame( 0, $plan->count( PlannedChange::DEACTIVATE ) );
	}

	public function test_a_team_that_changes_division_is_a_move(): void {
		$existing = Fixtures::cached_teams( 1 ); // team 90000 is in A Division
		$source   = SourceResult::exhausted( array( Fixtures::row( array(
			'teamId'      => 90000,
			'team'        => 'Test Team 1',
			'programName' => '2026 Summer Classic (B Division)',
		) ) ), 1 );

		$plan   = Fixtures::planner()->plan( Fixtures::config(), $source, $existing );
		$change = $plan->changes_of( PlannedChange::MOVE_DIVISION )[0];

		self::assertSame( 1, $plan->count( PlannedChange::MOVE_DIVISION ) );
		self::assertSame( 'a', $change->before->division_key );
		self::assertSame( 'b', $change->after->division_key );
		self::assertSame( 'A Division -> B Division', $change->reasons[0] );
	}

	public function test_a_team_absent_from_a_complete_read_is_deactivated(): void {
		$existing = Fixtures::cached_teams( 5 );
		$plan     = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_teams( 4 ), $existing );

		self::assertSame( 1, $plan->count( PlannedChange::DEACTIVATE ) );
		self::assertSame( SyncPlan::PASS, $plan->status );
	}

	/**
	 * The second of the three tests that matter most.
	 *
	 * An incomplete read looks exactly like teams withdrawing. If the plugin ever
	 * confuses the two, a timeout empties a public page.
	 */
	public function test_an_incomplete_read_plans_no_deactivations_at_all(): void {
		$existing = Fixtures::cached_teams( 84 );
		$partial  = SourceResult::incomplete( array(), 2, 'timeout', 'cURL error 28' );

		$plan = Fixtures::planner()->plan( Fixtures::config(), $partial, $existing );

		self::assertSame( SyncPlan::FAILED, $plan->status );
		self::assertTrue( $plan->has_error_code( 'INCOMPLETE_SOURCE' ) );
		self::assertSame( 0, $plan->count( PlannedChange::DEACTIVATE ) );
		self::assertSame( array(), $plan->writes() );
		self::assertFalse( $plan->safe_to_apply() );
		self::assertSame( 1, $plan->exit_code() );
	}

	public function test_a_rate_limited_read_is_failed_and_retryable(): void {
		$limited = SourceResult::incomplete( array(), 1, 'rate_limited', 'HTTP 429', 900, 429 );

		$plan = Fixtures::planner()->plan( Fixtures::config(), $limited, Fixtures::cached_teams( 5 ) );

		self::assertSame( SyncPlan::FAILED, $plan->status );
		self::assertSame( 900, $plan->source_meta['retry_after_seconds'] );
		self::assertTrue( $limited->is_retryable() );
	}

	/** A 401 is a configuration problem. Retrying it every five minutes fixes nothing. */
	public function test_an_unauthorised_read_is_not_retryable(): void {
		$denied = SourceResult::incomplete( array(), 0, 'http_error', 'HTTP 401', null, 401 );

		self::assertFalse( $denied->is_retryable() );
	}

	public function test_a_server_error_is_retryable(): void {
		self::assertTrue( SourceResult::incomplete( array(), 1, 'http_error', 'HTTP 503', null, 503 )->is_retryable() );
	}

	/**
	 * A cursor that stops advancing is an incomplete read, not a finished one.
	 *
	 * Without this the reader would either loop until the job is killed or, worse,
	 * stop and report what it had as the whole list.
	 */
	public function test_a_stalled_cursor_is_an_incomplete_read(): void {
		$stalled = SourceResult::incomplete( array( Fixtures::row() ), 3, 'cursor_stalled', 'cursor did not advance' );

		$plan = Fixtures::planner()->plan( Fixtures::config(), $stalled, Fixtures::cached_teams( 5 ) );

		self::assertTrue( $plan->has_error_code( 'INCOMPLETE_SOURCE' ) );
		self::assertSame( 0, $plan->count( PlannedChange::DEACTIVATE ) );
	}

	/**
	 * The first of the three tests that matter most.
	 *
	 * Under the default policy an unmapped division holds the team and warns. It
	 * does not guess, and it does not fail the whole run over two teams.
	 */
	public function test_an_unknown_division_is_held_for_review_by_default(): void {
		$source = SourceResult::exhausted( array( Fixtures::row( array( 'division' => 'Competitive Open' ) ) ), 1 );

		$plan = Fixtures::planner()->plan( Fixtures::config(), $source, array() );

		self::assertSame( SyncPlan::WARNING, $plan->status );
		self::assertTrue( $plan->has_warning_code( 'UNKNOWN_DIVISION' ) );
		self::assertSame( 1, $plan->count( PlannedChange::HOLD_FOR_REVIEW ) );
		self::assertSame( 0, $plan->count( PlannedChange::CREATE ) );
		self::assertFalse( $plan->safe_to_apply(), 'a warning must not be applied unattended' );
		self::assertSame( 2, $plan->exit_code() );
	}

	public function test_the_fail_sync_policy_blocks_the_whole_run(): void {
		$source = SourceResult::exhausted( array( Fixtures::row( array( 'division' => 'Competitive Open' ) ) ), 1 );

		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'unknown_division_policy' => EventConfig::FAIL_SYNC ) ),
			$source,
			array()
		);

		self::assertSame( SyncPlan::BLOCKED, $plan->status );
		self::assertTrue( $plan->has_error_code( 'UNKNOWN_DIVISION' ) );
		self::assertSame( 3, $plan->exit_code() );
	}

	public function test_the_show_pending_policy_publishes_under_a_pending_heading(): void {
		$source = SourceResult::exhausted( array( Fixtures::row( array( 'division' => 'Competitive Open' ) ) ), 1 );

		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'unknown_division_policy' => EventConfig::SHOW_PENDING ) ),
			$source,
			array()
		);

		$created = $plan->changes_of( PlannedChange::CREATE )[0];

		self::assertSame( SyncPlan::WARNING, $plan->status, 'still a warning: somebody should map it' );
		self::assertSame( SyncPlanner::PENDING_KEY, $created->after->division_key );
		self::assertSame( 'Division Pending', $created->after->division_label );
	}

	public function test_the_hide_policy_is_silent_because_the_operator_already_decided(): void {
		$source = SourceResult::exhausted( array( Fixtures::row( array( 'division' => 'Internal Test' ) ) ), 1 );

		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'unknown_division_policy' => EventConfig::HIDE ) ),
			$source,
			array()
		);

		self::assertSame( SyncPlan::NO_CHANGE, $plan->status );
		self::assertFalse( $plan->has_warning_code( 'UNKNOWN_DIVISION' ) );
		self::assertSame( 1, $plan->count( PlannedChange::HIDE_BY_POLICY ) );
	}

	/** 84 teams to 8 is a wrong program or a bad read, not 76 withdrawals. */
	public function test_it_blocks_a_mass_removal(): void {
		$plan = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_teams( 8 ), Fixtures::cached_teams( 84 ) );

		self::assertSame( SyncPlan::BLOCKED, $plan->status );
		self::assertTrue( $plan->has_error_code( 'MASS_DEACTIVATION_CIRCUIT_BREAKER' ) );
		self::assertSame( 76, $plan->count( PlannedChange::DEACTIVATE ) );
		self::assertSame( 90, $plan->source_meta['deactivation_percent'] );
		self::assertFalse( $plan->safe_to_apply() );
	}

	/** And it must not freeze ordinary operations. Two teams withdrawing is normal. */
	public function test_it_allows_an_ordinary_removal(): void {
		$plan = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_teams( 82 ), Fixtures::cached_teams( 84 ) );

		self::assertSame( SyncPlan::PASS, $plan->status );
		self::assertSame( 2, $plan->count( PlannedChange::DEACTIVATE ) );
		self::assertTrue( $plan->safe_to_apply() );
	}

	/** Both limits apply, so a small league is protected by the count. */
	public function test_the_count_limit_protects_a_small_league(): void {
		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'max_deactivation_count' => 2, 'max_deactivation_percent' => 90 ) ),
			Fixtures::source_teams( 5 ),
			Fixtures::cached_teams( 10 )
		);

		self::assertTrue( $plan->has_error_code( 'MASS_DEACTIVATION_CIRCUIT_BREAKER' ) );
	}

	public function test_an_operator_display_name_survives_a_sync(): void {
		$existing = array(
			90000 => new Team(
				event_key: Fixtures::EVENT,
				source_team_id: 90000,
				name: 'Test Team 1',
				division_key: 'a',
				division_label: 'A Division',
				roster_count: 1,
				display_name_override: 'Rangers',
			),
		);

		$plan = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_teams( 1 ), $existing );

		self::assertSame( SyncPlan::NO_CHANGE, $plan->status, 'the override already matched, so nothing changed' );
		self::assertSame( 'Rangers', $plan->changes[0]->after->display_name() );
		self::assertSame( 'Test Team 1', $plan->changes[0]->after->name, 'the source name is still kept alongside it' );
	}

	public function test_an_event_with_no_divisions_configured_cannot_run(): void {
		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'divisions' => new \LeagueAppsWP\Domain\DivisionMap( array() ) ) ),
			Fixtures::source_one_team(),
			array()
		);

		self::assertSame( SyncPlan::BLOCKED, $plan->status );
		self::assertTrue( $plan->has_error_code( 'DIVISION_MAP_EMPTY' ) );
	}

	/** A configuration failure stops before the source is even considered. */
	public function test_a_misconfigured_event_reports_no_planned_changes(): void {
		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'site_id' => 0 ) ),
			Fixtures::source_teams( 40 ),
			Fixtures::cached_teams( 40 )
		);

		self::assertTrue( $plan->has_error_code( 'SITE_NOT_CONFIGURED' ) );
		self::assertSame( array(), $plan->changes );
	}

	public function test_the_source_hash_is_stable_across_row_order(): void {
		$rows = array(
			Fixtures::row( array( 'teamId' => 1, 'team' => 'Alpha' ) ),
			Fixtures::row( array( 'teamId' => 2, 'team' => 'Beta' ) ),
		);

		$forwards  = Fixtures::planner()->plan( Fixtures::config(), SourceResult::exhausted( $rows, 1 ), array() );
		$backwards = Fixtures::planner()->plan( Fixtures::config(), SourceResult::exhausted( array_reverse( $rows ), 1 ), array() );

		self::assertSame( $forwards->source_hash, $backwards->source_hash );
	}

	public function test_the_source_hash_changes_when_a_visitor_would_see_something_different(): void {
		$before = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_teams( 5 ), array() );
		$after  = Fixtures::planner()->plan( Fixtures::config(), Fixtures::source_teams( 6 ), array() );

		self::assertNotSame( $before->source_hash, $after->source_hash );
	}

	/** A run that reaches the planner still carrying private data must stop. */
	public function test_a_denylisted_field_reaching_the_planner_blocks_the_run(): void {
		$leaked = SourceResult::exhausted( array( Fixtures::row() + array( 'email' => 'someone@example.com' ) ), 1 );

		$plan = Fixtures::planner()->plan( Fixtures::config(), $leaked, array() );

		self::assertSame( SyncPlan::BLOCKED, $plan->status );
		self::assertTrue( $plan->has_error_code( 'PRIVATE_FIELD_PRESENT' ) );
	}

	public function test_configured_programs_that_are_entirely_absent_are_an_identity_mismatch(): void {
		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'program_ids' => array( 111, 222 ) ) ),
			Fixtures::source_teams( 5 ),
			array()
		);

		self::assertSame( SyncPlan::BLOCKED, $plan->status );
		self::assertTrue( $plan->has_error_code( 'SOURCE_IDENTITY_MISMATCH' ) );
	}

	/** A configured program with no registrations yet is normal, not an error. */
	public function test_one_configured_program_with_no_registrations_is_only_a_warning(): void {
		$plan = Fixtures::planner()->plan(
			Fixtures::config( array( 'program_ids' => array( Fixtures::PROGRAM, 999 ) ) ),
			Fixtures::source_teams( 5 ),
			array()
		);

		self::assertSame( SyncPlan::WARNING, $plan->status );
		self::assertTrue( $plan->has_warning_code( 'PROGRAM_NOT_IN_SOURCE' ) );
	}

	public function test_an_empty_but_successful_read_warns_rather_than_failing(): void {
		$plan = Fixtures::planner()->plan( Fixtures::config(), SourceResult::exhausted( array(), 1 ), array() );

		self::assertSame( SyncPlan::WARNING, $plan->status );
		self::assertTrue( $plan->has_warning_code( 'SOURCE_EMPTY' ) );
	}
}
