<?php
/**
 * The dry-run guarantee, and the invariant that replaces a guard mutation proved
 * was dead.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\PlannedChange;
use LeagueAppsWP\Domain\SourceResult;
use LeagueAppsWP\Domain\SyncPlan;
use LeagueAppsWP\Infrastructure\ReadOnlyTeamRepository;
use LeagueAppsWP\Service\SyncApplier;
use LeagueAppsWP\Tests\Support\FrozenClock;
use LeagueAppsWP\Tests\Support\Fixtures;
use LeagueAppsWP\Tests\Support\InMemoryTeamRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DryRunTest extends TestCase {

	/**
	 * The third of the three tests that matter most.
	 *
	 * Asserting the stored teams are unchanged would be weaker: a write that puts
	 * back an identical value is still a write. It bumps a timestamp, it is still
	 * a row the applier reported, and on a live site it would purge a page cache.
	 * So the assertion is on the write COUNT.
	 */
	public function test_a_dry_run_builds_the_full_diff_and_writes_nothing(): void {
		$repository = new InMemoryTeamRepository( Fixtures::cached_teams( 5 ) );

		$plan = Fixtures::planner()->plan(
			Fixtures::config(),
			Fixtures::source_teams( 7 ),
			$repository->active_for_event( Fixtures::EVENT )
		);

		self::assertSame( 2, $plan->count( PlannedChange::CREATE ) );
		self::assertSame( 5, $plan->count( PlannedChange::NO_CHANGE ) );
		self::assertSame( 0, $repository->write_count() );
		self::assertCount( 5, $repository->active_for_event( Fixtures::EVENT ) );
		self::assertSame( 0, $repository->open_transactions() );
	}

	/**
	 * The planner has no write path at all, proved by handing it a repository
	 * that throws on every write method.
	 */
	public function test_the_planner_cannot_write_even_when_writing_would_throw(): void {
		$inner    = new InMemoryTeamRepository( Fixtures::cached_teams( 84 ) );
		$readonly = new ReadOnlyTeamRepository( $inner );

		$plan = Fixtures::planner()->plan(
			Fixtures::config(),
			Fixtures::source_teams( 82 ),
			$readonly->active_for_event( Fixtures::EVENT )
		);

		self::assertSame( 2, $plan->count( PlannedChange::DEACTIVATE ) );
		self::assertSame( 0, $inner->write_count() );
	}

	public function test_the_read_only_repository_refuses_each_write_method(): void {
		$readonly = new ReadOnlyTeamRepository( new InMemoryTeamRepository() );
		$team     = Fixtures::cached_teams( 1 )[90000];

		foreach ( array(
			'upsert'     => static fn() => $readonly->upsert( $team ),
			'deactivate' => static fn() => $readonly->deactivate( Fixtures::EVENT, 90000 ),
			'record_run' => static fn() => $readonly->record_run( array() ),
		) as $method => $call ) {
			try {
				$call();
				self::fail( "$method should have thrown" );
			} catch ( \LogicException $e ) {
				self::assertStringContainsString( 'dry run', $e->getMessage() );
			}
		}
	}

	/**
	 * The first half of the invariant that replaced a dead guard.
	 *
	 * A read that did not finish cannot know what is absent, so it must not
	 * produce a deactivation ENTRY at all. There is nothing to review: the rows
	 * might be perfectly fine and simply not have arrived.
	 */
	#[DataProvider( 'unreadable_sources' )]
	public function test_an_unreadable_source_never_plans_a_deactivation( string $case, SourceResult $source ): void {
		$plan = Fixtures::planner()->plan( Fixtures::config(), $source, Fixtures::cached_teams( 84 ) );

		self::assertSame( SyncPlan::FAILED, $plan->status, $case );
		self::assertSame( 0, $plan->count( PlannedChange::DEACTIVATE ), "$case planned a deactivation" );
		self::assertSame( array(), $plan->writes(), $case );
	}

	public static function unreadable_sources(): iterable {
		yield 'timeout'        => array( 'timeout', SourceResult::incomplete( array(), 2, 'timeout', 'cURL error 28' ) );
		yield 'rate limited'   => array( 'rate limited', SourceResult::incomplete( array(), 1, 'rate_limited', 'HTTP 429', 900, 429 ) );
		yield 'unauthorised'   => array( 'unauthorised', SourceResult::incomplete( array(), 0, 'http_error', 'HTTP 401', null, 401 ) );
		yield 'server error'   => array( 'server error', SourceResult::incomplete( array(), 1, 'http_error', 'HTTP 503', null, 503 ) );
		yield 'stalled cursor' => array( 'stalled cursor', SourceResult::incomplete( array( Fixtures::row() ), 3, 'cursor_stalled', 'cursor did not advance' ) );
		yield 'page cap hit'   => array( 'page cap hit', SourceResult::incomplete( array( Fixtures::row() ), 50, 'page_cap', 'stopped at the page cap' ) );
		yield 'html not json'  => array( 'html not json', SourceResult::incomplete( array(), 1, 'not_json', 'text/html returned' ) );
	}

	/**
	 * The second half, and the one that took a failing test to get right.
	 *
	 * A plan blocked by a circuit breaker or a policy DOES list its deactivations,
	 * and should: the whole point of the report is to show an operator the 76
	 * removals it is refusing to make. The invariant is not that the entries are
	 * absent. It is that nothing reaches the database.
	 *
	 * Asserted through the applier, because that is the live guard.
	 */
	#[DataProvider( 'unsafe_sources' )]
	public function test_no_unsafe_plan_is_ever_applied( string $case, SourceResult $source, array $config ): void {
		$repository = new InMemoryTeamRepository( Fixtures::cached_teams( 84 ) );
		$applier    = new SyncApplier( $repository, new FrozenClock() );

		$plan = Fixtures::planner()->plan(
			Fixtures::config( $config ),
			$source,
			$repository->active_for_event( Fixtures::EVENT ),
			'run-one',
			( new FrozenClock() )->now()->format( \DateTimeInterface::RFC3339 )
		);

		self::assertFalse( $plan->safe_to_apply(), $case );

		// Both an unattended run and a person overriding warnings.
		self::assertFalse( $applier->apply( $plan )->applied, $case );
		self::assertFalse( $applier->apply( $plan, '', true )->applied, "$case applied under --allow-warnings" );

		self::assertSame( 0, $repository->write_count(), "$case wrote to the cache" );
		self::assertCount( 84, $repository->active_for_event( Fixtures::EVENT ), "$case changed the published list" );
	}

	public static function unsafe_sources(): iterable {
		yield 'timeout'          => array( 'timeout', SourceResult::incomplete( array(), 2, 'timeout', 'cURL error 28' ), array() );
		yield 'rate limited'     => array( 'rate limited', SourceResult::incomplete( array(), 1, 'rate_limited', 'HTTP 429', 900, 429 ), array() );
		yield 'unauthorised'     => array( 'unauthorised', SourceResult::incomplete( array(), 0, 'http_error', 'HTTP 401', null, 401 ), array() );
		yield 'server error'     => array( 'server error', SourceResult::incomplete( array(), 1, 'http_error', 'HTTP 503', null, 503 ), array() );
		yield 'stalled cursor'   => array( 'stalled cursor', SourceResult::incomplete( array( Fixtures::row() ), 3, 'cursor_stalled', 'cursor did not advance' ), array() );
		yield 'page cap hit'     => array( 'page cap hit', SourceResult::incomplete( array( Fixtures::row() ), 50, 'page_cap', 'stopped at the page cap' ), array() );
		yield 'html not json'    => array( 'html not json', SourceResult::incomplete( array(), 1, 'not_json', 'text/html returned' ), array() );
		yield 'mass removal'     => array( 'mass removal', Fixtures::source_teams( 8 ), array() );
		yield 'wrong programs'   => array( 'wrong programs', Fixtures::source_teams( 84 ), array( 'program_ids' => array( 111, 222 ) ) );
		yield 'no division map'  => array( 'no division map', Fixtures::source_teams( 84 ), array( 'divisions' => new \LeagueAppsWP\Domain\DivisionMap( array() ) ) );
		yield 'private field'    => array( 'private field', SourceResult::exhausted( array( Fixtures::row() + array( 'email' => 'x@example.com' ) ), 1 ), array() );
		yield 'unknown division' => array(
			'unknown division',
			SourceResult::exhausted( array( Fixtures::row( array( 'division' => 'Competitive Open' ) ) ), 1 ),
			array( 'unknown_division_policy' => EventConfig::FAIL_SYNC ),
		);
	}

	/** An empty read is the case that most looks like every team withdrawing. */
	public function test_an_empty_successful_read_does_not_empty_the_page(): void {
		$plan = Fixtures::planner()->plan( Fixtures::config(), SourceResult::exhausted( array(), 1 ), Fixtures::cached_teams( 84 ) );

		self::assertFalse( $plan->safe_to_apply() );
		self::assertTrue( $plan->has_error_code( 'MASS_DEACTIVATION_CIRCUIT_BREAKER' ) );
	}
}
