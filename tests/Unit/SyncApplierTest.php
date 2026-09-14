<?php

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\SourceResult;
use LeagueAppsWP\Service\SyncApplier;
use LeagueAppsWP\Tests\Support\FailingTeamRepository;
use LeagueAppsWP\Tests\Support\Fixtures;
use LeagueAppsWP\Tests\Support\FrozenClock;
use LeagueAppsWP\Tests\Support\InMemoryTeamRepository;
use PHPUnit\Framework\TestCase;

final class SyncApplierTest extends TestCase {

	private function applier( \LeagueAppsWP\Contracts\TeamRepositoryInterface $repository, ?FrozenClock $clock = null ): SyncApplier {
		return new SyncApplier( $repository, $clock ?? new FrozenClock() );
	}

	private function plan( $source, array $existing = array(), array $config = array(), string $planned_at = '2026-09-14T15:15:00+00:00' ) {
		return Fixtures::planner()->plan( Fixtures::config( $config ), $source, $existing, 'run-one', $planned_at );
	}

	public function test_it_writes_only_the_planned_changes(): void {
		$repository = new InMemoryTeamRepository( Fixtures::cached_teams( 5 ) );
		$plan       = $this->plan( Fixtures::source_teams( 7 ), $repository->active_for_event( Fixtures::EVENT ) );

		$result = $this->applier( $repository )->apply( $plan );

		self::assertTrue( $result->applied );
		self::assertSame( 2, $result->writes, 'two creates, and the five unchanged teams are not rewritten' );
		self::assertCount( 7, $repository->active_for_event( Fixtures::EVENT ) );
	}

	public function test_a_second_apply_of_the_same_state_writes_nothing(): void {
		$repository = new InMemoryTeamRepository( Fixtures::cached_teams( 5 ) );

		$this->applier( $repository )->apply( $this->plan( Fixtures::source_teams( 7 ), $repository->active_for_event( Fixtures::EVENT ) ) );
		$writes_after_first = $repository->write_count();

		$second = $this->applier( $repository )->apply( $this->plan( Fixtures::source_teams( 7 ), $repository->active_for_event( Fixtures::EVENT ) ) );

		self::assertTrue( $second->applied );
		self::assertFalse( $second->content_changed );
		self::assertSame( $writes_after_first, $repository->write_count(), 'idempotent: nothing written the second time' );
	}

	public function test_it_refuses_a_blocked_plan(): void {
		$repository = new InMemoryTeamRepository( Fixtures::cached_teams( 84 ) );
		$plan       = $this->plan( Fixtures::source_teams( 8 ), $repository->active_for_event( Fixtures::EVENT ) );

		$result = $this->applier( $repository )->apply( $plan );

		self::assertFalse( $result->applied );
		self::assertSame( 'PLAN_NOT_SAFE_TO_APPLY', $result->reason );
		self::assertSame( 0, $repository->write_count() );
		self::assertCount( 84, $repository->active_for_event( Fixtures::EVENT ), 'the published list is untouched' );
	}

	public function test_it_refuses_a_failed_plan_even_when_warnings_are_allowed(): void {
		$repository = new InMemoryTeamRepository( Fixtures::cached_teams( 84 ) );
		$plan       = $this->plan( SourceResult::incomplete( array(), 1, 'timeout', 'cURL error 28' ), $repository->active_for_event( Fixtures::EVENT ) );

		$result = $this->applier( $repository )->apply( $plan, '', true );

		self::assertFalse( $result->applied );
		self::assertSame( 0, $repository->write_count() );
	}

	public function test_an_unattended_run_refuses_a_warning_plan(): void {
		$repository = new InMemoryTeamRepository();
		$source     = SourceResult::exhausted( array( Fixtures::row( array( 'division' => 'Competitive Open' ) ), Fixtures::row( array( 'teamId' => 2 ) ) ), 1 );
		$plan       = $this->plan( $source );

		self::assertFalse( $this->applier( $repository )->apply( $plan )->applied );
		self::assertSame( 0, $repository->write_count() );
	}

	public function test_a_person_may_apply_a_warning_plan_deliberately(): void {
		$repository = new InMemoryTeamRepository();
		$source     = SourceResult::exhausted( array( Fixtures::row( array( 'division' => 'Competitive Open' ) ), Fixtures::row( array( 'teamId' => 2 ) ) ), 1 );

		$result = $this->applier( $repository )->apply( $this->plan( $source ), '', true );

		self::assertTrue( $result->applied );
		self::assertCount( 1, $repository->active_for_event( Fixtures::EVENT ), 'the held team is still not published' );
	}

	/**
	 * An approved plan is a claim about one source state. If the source moved on,
	 * applying it would write a division assignment nobody reviewed.
	 */
	public function test_it_refuses_an_approved_plan_whose_source_has_since_changed(): void {
		$repository = new InMemoryTeamRepository();
		$plan       = $this->plan( Fixtures::source_teams( 5 ) );

		$result = $this->applier( $repository )->apply( $plan, 'sha256:something-else' );

		self::assertFalse( $result->applied );
		self::assertSame( 'PLAN_SUPERSEDED', $result->reason );
		self::assertSame( 0, $repository->write_count() );
	}

	public function test_it_accepts_an_approved_plan_whose_source_still_matches(): void {
		$repository = new InMemoryTeamRepository();
		$plan       = $this->plan( Fixtures::source_teams( 5 ) );

		self::assertTrue( $this->applier( $repository )->apply( $plan, $plan->source_hash )->applied );
	}

	public function test_it_refuses_a_plan_that_has_gone_stale(): void {
		$repository = new InMemoryTeamRepository();
		$clock      = new FrozenClock( '2026-09-14T15:15:00+00:00' );
		$plan       = $this->plan( Fixtures::source_teams( 5 ), array(), array(), '2026-09-14T15:15:00+00:00' );

		$clock->advance( 'PT31M' );

		$result = $this->applier( $repository, $clock )->apply( $plan );

		self::assertFalse( $result->applied );
		self::assertSame( 'PLAN_STALE', $result->reason );
		self::assertSame( 0, $repository->write_count() );
	}

	public function test_a_plan_inside_the_window_still_applies(): void {
		$repository = new InMemoryTeamRepository();
		$clock      = new FrozenClock( '2026-09-14T15:15:00+00:00' );
		$plan       = $this->plan( Fixtures::source_teams( 5 ), array(), array(), '2026-09-14T15:15:00+00:00' );

		$clock->advance( 'PT29M' );

		self::assertTrue( $this->applier( $repository, $clock )->apply( $plan )->applied );
	}

	public function test_a_failing_write_rolls_back_and_keeps_the_last_good_list(): void {
		$repository = new FailingTeamRepository( Fixtures::cached_teams( 5 ) );

		$plan   = $this->plan( Fixtures::source_teams( 7 ), $repository->active_for_event( Fixtures::EVENT ) );
		$result = $this->applier( $repository )->apply( $plan );

		self::assertFalse( $result->applied );
		self::assertStringStartsWith( 'APPLY_FAILED', $result->reason );
		self::assertCount( 5, $repository->active_for_event( Fixtures::EVENT ) );
		self::assertSame( 0, $repository->open_transactions(), 'the transaction was closed' );
	}

	/** The run manifest is an audit record, so it must carry no team names. */
	public function test_the_recorded_manifest_carries_counts_and_codes_only(): void {
		$repository = new InMemoryTeamRepository();
		$this->applier( $repository )->apply( $this->plan( Fixtures::source_teams( 3 ) ) );

		$manifest = $repository->runs()[0];

		self::assertSame( 'run-one', $manifest['run_id'] );
		self::assertSame( 3, $manifest['summary']['create'] );
		self::assertStringNotContainsString( 'Test Team', (string) json_encode( $manifest ) );
	}
}
