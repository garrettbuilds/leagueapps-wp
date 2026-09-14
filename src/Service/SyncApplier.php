<?php
/**
 * Writes a plan to the cache, or refuses to.
 *
 * This is the second layer of protection, and unlike the one that was removed
 * from the planner, it is reachable: every guard here has a test that fails when
 * the guard is deleted.
 *
 * It never fetches, never normalises and never decides anything about divisions.
 * If this class had to re-read the source to know what to write, "the plan you
 * reviewed" and "the plan that ran" could differ, which is the exact failure the
 * plan/apply split exists to prevent.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Service;

use LeagueAppsWP\Contracts\ClockInterface;
use LeagueAppsWP\Contracts\TeamRepositoryInterface;
use LeagueAppsWP\Domain\ApplyResult;
use LeagueAppsWP\Domain\PlannedChange;
use LeagueAppsWP\Domain\SyncPlan;

final class SyncApplier {

	/**
	 * How old an approved plan may be before it must be rebuilt.
	 *
	 * Thirty minutes because a plan is a claim about LeagueApps at one moment.
	 * An operator who reviews a plan, goes to lunch and comes back to press Apply
	 * would otherwise write a division assignment that has since changed.
	 */
	public const MAX_PLAN_AGE_SECONDS = 1800;

	public function __construct(
		private readonly TeamRepositoryInterface $repository,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * @param string $expected_hash If given, the plan must still describe this
	 *                              source state. Used by an approved apply, where
	 *                              a person reviewed one specific plan.
	 * @param bool   $allow_warnings A person may apply a warning plan deliberately.
	 *                              An unattended run never may.
	 */
	public function apply( SyncPlan $plan, string $expected_hash = '', bool $allow_warnings = false ): ApplyResult {
		/*
		 * One guard, not two.
		 *
		 * This was written as two checks, the second re-refusing BLOCKED and
		 * FAILED in case the warnings allowance was ever widened. Mutation
		 * testing deleted it and all 99 tests still passed, because the first
		 * check already covers both. WARNING is the only status the allowance
		 * can ever reach, and saying so once in a condition somebody has to read
		 * is safer than saying it twice in a branch nothing exercises.
		 */
		$applicable = $plan->safe_to_apply()
			|| ( $allow_warnings && SyncPlan::WARNING === $plan->status );

		if ( ! $applicable ) {
			return ApplyResult::refused( 'PLAN_NOT_SAFE_TO_APPLY' );
		}

		if ( '' !== $expected_hash && $expected_hash !== $plan->source_hash ) {
			return ApplyResult::refused( 'PLAN_SUPERSEDED' );
		}

		if ( $this->is_stale( $plan ) ) {
			return ApplyResult::refused( 'PLAN_STALE' );
		}

		$writes = $plan->writes();

		if ( array() === $writes ) {
			return ApplyResult::nothing_to_do( $plan->source_hash );
		}

		$this->repository->begin();

		try {
			foreach ( $writes as $change ) {
				if ( PlannedChange::DEACTIVATE === $change->action ) {
					$this->repository->deactivate( $plan->event_key, (int) $change->source_team_id() );
					continue;
				}

				$this->repository->upsert( $change->after );
			}

			$this->repository->record_run( $this->manifest( $plan ) );
			$this->repository->commit();
		} catch ( \Throwable $e ) {
			// Roll back and keep the last validated list on the page. A partly
			// applied plan is the one state worse than an out-of-date one.
			$this->repository->rollback();
			return ApplyResult::refused( 'APPLY_FAILED: ' . $e->getMessage() );
		}

		/*
		 * Did the write actually take?
		 *
		 * A field can be added to Team and to visible_hash() and forgotten in the
		 * repository's column list. Nothing errors: the row is written, the new
		 * value is dropped, and because the hash still says the data differs,
		 * EVERY subsequent run plans the same updates again. A sync that never
		 * converges and never complains.
		 *
		 * That happened, with `location`, and it was caught by a person reading a
		 * log rather than by anything here. Re-reading the rows and comparing
		 * hashes is cheap and catches the whole class.
		 */
		$stored = $this->repository->active_for_event( $plan->event_key );
		$drifted = 0;

		foreach ( $writes as $change ) {
			if ( PlannedChange::DEACTIVATE === $change->action || null === $change->after ) {
				continue;
			}

			$after = $stored[ $change->after->source_team_id ] ?? null;

			if ( null === $after || $after->visible_hash() !== $change->after->visible_hash() ) {
				++$drifted;
			}
		}

		if ( $drifted > 0 ) {
			return ApplyResult::refused( sprintf(
				'WRITE_DID_NOT_PERSIST: %d row(s) read back different from what was planned. A field is probably in visible_hash() but missing from the repository, which makes every run repeat these changes for ever.',
				$drifted
			) );
		}

		return ApplyResult::applied( count( $writes ), true, $plan->source_hash );
	}

	private function is_stale( SyncPlan $plan ): bool {
		if ( '' === $plan->planned_at ) {
			return false;
		}

		$planned = \DateTimeImmutable::createFromFormat( \DateTimeInterface::RFC3339, $plan->planned_at )
			?: new \DateTimeImmutable( $plan->planned_at );

		return ( $this->clock->now()->getTimestamp() - $planned->getTimestamp() ) > self::MAX_PLAN_AGE_SECONDS;
	}

	/** Counts, codes and ids. No team names, no raw rows, nothing personal. */
	private function manifest( SyncPlan $plan ): array {
		return array(
			'run_id'      => $plan->run_id,
			'event_key'   => $plan->event_key,
			'status'      => $plan->status,
			'summary'     => $plan->summary,
			'source_hash' => $plan->source_hash,
			'planned_at'  => $plan->planned_at,
			'applied_at'  => $this->clock->now()->format( \DateTimeInterface::RFC3339 ),
			'error_codes' => array_column( $plan->errors, 'code' ),
			'warning_codes' => array_column( $plan->warnings, 'code' ),
		);
	}
}
