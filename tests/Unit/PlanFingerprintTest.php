<?php
/**
 * The guard behind "publish what I reviewed".
 *
 * The admin screen reviews a plan in one request and publishes in a second. The
 * second request has to re-read LeagueApps to write current data, so the only
 * thing standing between a person's approval and a silently different write is
 * this comparison. A fingerprint that cannot tell two plans apart is worse than
 * no two-step at all, because the screen would claim a review happened.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Tests\Unit;

use LeagueAppsWP\Domain\SyncPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanFingerprintTest extends TestCase {

	/**
	 * Run id and planned-at differ on every plan the planner builds.
	 *
	 * This is the test that stops somebody "improving" fingerprint() into a hash
	 * of the whole object. That version compares unequal every time, so publish
	 * refuses forever and the fix looks like removing the guard.
	 */
	public function test_identical_decisions_match_across_separate_runs(): void {
		$first  = $this->plan( run_id: 'admin-aaaa1111', planned_at: '2026-09-15T09:00:00+00:00' );
		$second = $this->plan( run_id: 'admin-bbbb2222', planned_at: '2026-09-15T09:04:31+00:00' );

		self::assertSame(
			$first->fingerprint(),
			$second->fingerprint(),
			'A re-plan of unchanged data must still be publishable.'
		);
	}

	/**
	 * Each of these is a real way the world moves between the two clicks, and
	 * each must stop the publish.
	 *
	 * @param array<string, mixed> $difference
	 */
	#[DataProvider( 'divergences' )]
	public function test_a_changed_plan_no_longer_matches( array $difference ): void {
		$reviewed = $this->plan();
		$atPublish = $this->plan( ...$difference );

		self::assertNotSame(
			$reviewed->fingerprint(),
			$atPublish->fingerprint(),
			'This change must force a fresh review.'
		);
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function divergences(): array {
		return array(
			'a team registered, so LeagueApps returns different data' => array(
				array( 'source_hash' => 'sha256:a-different-export' ),
			),
			'the planner now holds something back'                    => array(
				array( 'status' => SyncPlan::WARNING ),
			),
			'there are more changes than were reviewed'               => array(
				array( 'changes' => array( 'one', 'two', 'three' ) ),
			),
			'the counts moved without the change count moving'        => array(
				array( 'summary' => array( 'creates' => 2, 'updates' => 0, 'deactivations' => 0 ) ),
			),
		);
	}

	/**
	 * A plan carrying no changes still has to fingerprint, because "nothing to
	 * do" is a reviewable outcome and must not collide with a plan that does
	 * something.
	 */
	public function test_an_empty_plan_is_distinguishable_from_a_populated_one(): void {
		$nothing  = $this->plan( status: SyncPlan::NO_CHANGE, changes: array(), summary: array() );
		$something = $this->plan();

		self::assertNotSame( $nothing->fingerprint(), $something->fingerprint() );
		self::assertNotSame( '', $nothing->fingerprint() );
	}

	private function plan(
		string $run_id = 'admin-deadbeef',
		string $status = SyncPlan::PASS,
		array $changes = array( 'one', 'two' ),
		array $summary = array( 'creates' => 1, 'updates' => 1, 'deactivations' => 0 ),
		string $source_hash = 'sha256:the-export-we-reviewed',
		string $planned_at = '2026-09-15T09:00:00+00:00'
	): SyncPlan {
		return new SyncPlan(
			run_id: $run_id,
			event_key: 'summer-tournament',
			status: $status,
			changes: $changes,
			summary: $summary,
			source_hash: $source_hash,
			planned_at: $planned_at
		);
	}
}
