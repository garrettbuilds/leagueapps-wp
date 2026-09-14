<?php
/**
 * Builds a plan. Writes nothing, ever.
 *
 * Every read path in this class goes through the repository's read method and
 * the client's result object. There is no $wpdb here, no wp_remote_get, and no
 * call to anything that can persist. A dry run is this class plus a report; a
 * live run is this class plus SyncApplier. That is the whole safety model, and
 * it holds because there is no second "preview" implementation to drift.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Service;

use LeagueAppsWP\Domain\DivisionMatch;
use LeagueAppsWP\Domain\EventConfig;
use LeagueAppsWP\Domain\PlannedChange;
use LeagueAppsWP\Domain\SourceResult;
use LeagueAppsWP\Domain\SyncPlan;
use LeagueAppsWP\Domain\Team;
use LeagueAppsWP\Domain\ValidationResult;

final class SyncPlanner {

	/** Synthetic division for the show_pending policy. Never a configured key. */
	public const PENDING_KEY   = '__pending';
	public const PENDING_LABEL = 'Division Pending';

	public function __construct(
		private readonly SourceValidator $validator,
		private readonly TeamNormalizer $normalizer,
	) {}

	/**
	 * @param array<int,Team> $existing Currently active cache rows, keyed by source_team_id.
	 */
	public function plan( EventConfig $config, SourceResult $source, array $existing, string $run_id = '', string $planned_at = '' ): SyncPlan {
		$run_id     = '' !== $run_id ? $run_id : bin2hex( random_bytes( 8 ) );
		$planned_at = '' !== $planned_at ? $planned_at : gmdate( 'c' );

		$validation = $this->validator->validate_config( $config );

		// A misconfigured event must not reach the network. Stop before the fetch
		// is even considered relevant.
		if ( ! $validation->is_valid() ) {
			return $this->stop( $run_id, $config, $validation, $planned_at );
		}

		$validation->merge( $this->validator->validate_source( $source, $config ) );

		if ( ! $validation->is_valid() ) {
			return $this->stop( $run_id, $config, $validation, $planned_at );
		}

		$normalized = $this->normalizer->normalize( $source->rows, $config );
		$validation->merge( $normalized->validation );

		[ $teams, $changes ] = $this->apply_unknown_division_policy( $config, $normalized->teams, $normalized->held, $validation );

		foreach ( $normalized->excluded as $excluded ) {
			$changes[] = new PlannedChange( PlannedChange::EXCLUDE_INVALID, null, null, array( $excluded['reason'] ) );
		}

		$changes = array_merge( $changes, $this->diff( $teams, $existing ) );
		$changes = array_merge( $changes, $this->plan_deactivations( $teams, $existing ) );

		$summary = $this->summarise( $changes );

		$this->check_circuit_breaker( $config, $summary, $existing, $validation );

		return new SyncPlan(
			run_id: $run_id,
			event_key: $config->event_key,
			status: $this->status( $validation, $summary ),
			changes: $changes,
			summary: $summary,
			errors: $validation->errors(),
			warnings: $validation->warnings(),
			source_hash: $this->hash( $teams ),
			division_report: $normalized->division_report,
			source_meta: $validation->meta(),
			planned_at: $planned_at,
			skipped: $normalized->skipped,
		);
	}

	/**
	 * What happens to a team whose division we cannot place.
	 *
	 * Four policies because the right answer changes across a season. Early in
	 * registration, holding two teams back is a small cost and publishing them
	 * in a guessed division is a real one. Once the list is operationally
	 * important, an unknown division should stop the sync and get a person's
	 * attention instead.
	 *
	 * @param array<int,Team> $teams
	 * @return array{0:array<int,Team>,1:PlannedChange[]}
	 */
	private function apply_unknown_division_policy( EventConfig $config, array $teams, array $held, ValidationResult $validation ): array {
		$changes  = array();
		$unknown  = 0;

		foreach ( $held as $entry ) {
			/** @var Team $team */
			$team   = $entry['team'];
			$reason = $entry['reason'];

			// A team below the roster minimum or in a hidden division is not an
			// unknown division and is not the policy's business.
			if ( 'BELOW_MIN_ROSTER' === $reason || 'DIVISION_HIDDEN' === $reason ) {
				$changes[] = new PlannedChange( PlannedChange::HIDE_BY_POLICY, null, $team, array( $reason ) );
				continue;
			}

			++$unknown;

			if ( $config->policy_is( EventConfig::SHOW_PENDING ) ) {
				$teams[ $team->source_team_id ] = $team->with( array(
					'division_key'   => self::PENDING_KEY,
					'division_label' => self::PENDING_LABEL,
				) );
				continue;
			}

			if ( $config->policy_is( EventConfig::HIDE ) ) {
				$changes[] = new PlannedChange( PlannedChange::HIDE_BY_POLICY, null, $team, array( $reason ) );
				continue;
			}

			$changes[] = new PlannedChange( PlannedChange::HOLD_FOR_REVIEW, null, $team, array( $reason, $entry['source_value'] ) );
		}

		if ( 0 === $unknown ) {
			return array( $teams, $changes );
		}

		$message = sprintf(
			'%d team(s) have a division this Site has not mapped. Add a mapping, or confirm the policy for them.',
			$unknown
		);

		if ( $config->policy_is( EventConfig::FAIL_SYNC ) ) {
			$validation->error( 'UNKNOWN_DIVISION', $message, array( 'class' => 'blocked', 'count' => $unknown ) );
		} elseif ( ! $config->policy_is( EventConfig::HIDE ) ) {
			$validation->warn( 'UNKNOWN_DIVISION', $message, array( 'count' => $unknown ) );
		}

		return array( $teams, $changes );
	}

	/**
	 * @param array<int,Team> $teams
	 * @param array<int,Team> $existing
	 * @return PlannedChange[]
	 */
	private function diff( array $teams, array $existing ): array {
		$changes = array();

		foreach ( $teams as $id => $team ) {
			$before = $existing[ $id ] ?? null;

			if ( null === $before ) {
				$changes[] = new PlannedChange( PlannedChange::CREATE, null, $team );
				continue;
			}

			// Carry a stored operator override forward. A sync must not silently
			// revert a display name a person chose deliberately.
			if ( '' !== $before->display_name_override ) {
				$team = $team->with( array( 'display_name_override' => $before->display_name_override ) );
			}

			if ( $before->visible_hash() === $team->visible_hash() ) {
				$changes[] = new PlannedChange( PlannedChange::NO_CHANGE, $before, $team );
				continue;
			}

			// Most specific action first: a division move is the change an
			// operator most needs to see, and it usually arrives alone.
			if ( $before->division_key !== $team->division_key ) {
				$changes[] = new PlannedChange( PlannedChange::MOVE_DIVISION, $before, $team, array(
					sprintf( '%s -> %s', $before->division_label ?? '(none)', $team->division_label ?? '(none)' ),
				) );
				continue;
			}

			if ( $before->display_name() !== $team->display_name() ) {
				$changes[] = new PlannedChange( PlannedChange::RENAME, $before, $team, array(
					sprintf( '%s -> %s', $before->display_name(), $team->display_name() ),
				) );
				continue;
			}

			$changes[] = new PlannedChange( PlannedChange::UPDATE, $before, $team );
		}

		return $changes;
	}

	/**
	 * Teams in the cache that the source no longer returns.
	 *
	 * THE GATE ON THIS IS THE EARLY RETURN IN plan(), and it is the only one.
	 *
	 * There used to be a second check here, testing for INCOMPLETE_SOURCE again
	 * before planning any removal. It read like defence in depth and was dead
	 * code: an incomplete read never reaches this method. Mutation testing proved
	 * it, by deleting the check and watching all 64 tests still pass. Untested
	 * protection is worse than none, because it invites the next reader to trust
	 * it. The real second layer is in SyncApplier, which refuses a plan that is
	 * not safe to apply, and which does have tests that fail when it is removed.
	 *
	 * @param array<int,Team> $teams
	 * @param array<int,Team> $existing
	 * @return PlannedChange[]
	 */
	private function plan_deactivations( array $teams, array $existing ): array {
		$changes = array();

		foreach ( $existing as $id => $before ) {
			if ( ! isset( $teams[ $id ] ) ) {
				$changes[] = new PlannedChange( PlannedChange::DEACTIVATE, $before, null, array( 'ABSENT_FROM_SOURCE' ) );
			}
		}

		return $changes;
	}

	/**
	 * Refuse a suspiciously large removal.
	 *
	 * A source that reports 8 teams where the cache holds 84 is far more likely
	 * to be the wrong program, a changed key scope, or a response that ended
	 * early in a way we failed to detect, than 76 teams withdrawing at once.
	 * Both thresholds apply: a count, so a small league is protected, and a
	 * percentage, so a large one is.
	 */
	private function check_circuit_breaker( EventConfig $config, array $summary, array $existing, ValidationResult $validation ): void {
		$deactivations = (int) ( $summary[ PlannedChange::DEACTIVATE ] ?? 0 );

		if ( 0 === $deactivations ) {
			return;
		}

		$cached  = count( $existing );
		$percent = $cached > 0 ? (int) round( $deactivations / $cached * 100 ) : 100;

		$over_count   = $deactivations > $config->max_deactivation_count;
		$over_percent = $percent > $config->max_deactivation_percent;

		$validation->note( 'deactivation_percent', $percent );

		if ( ! $over_count && ! $over_percent ) {
			return;
		}

		$validation->error(
			'MASS_DEACTIVATION_CIRCUIT_BREAKER',
			sprintf(
				'This run would remove %d of %d teams (%d%%), above the configured limit of %d teams or %d%%. The published list is unchanged until somebody confirms this.',
				$deactivations,
				$cached,
				$percent,
				$config->max_deactivation_count,
				$config->max_deactivation_percent
			),
			array( 'class' => 'blocked', 'deactivations' => $deactivations, 'cached' => $cached, 'percent' => $percent )
		);
	}

	/** @param PlannedChange[] $changes */
	private function summarise( array $changes ): array {
		$summary = array_fill_keys( array(
			PlannedChange::CREATE,
			PlannedChange::UPDATE,
			PlannedChange::RENAME,
			PlannedChange::MOVE_DIVISION,
			PlannedChange::DEACTIVATE,
			PlannedChange::NO_CHANGE,
			PlannedChange::HOLD_FOR_REVIEW,
			PlannedChange::HIDE_BY_POLICY,
			PlannedChange::EXCLUDE_INVALID,
		), 0 );

		foreach ( $changes as $change ) {
			++$summary[ $change->action ];
		}

		return $summary;
	}

	/**
	 * Five outcomes, not two.
	 *
	 * "Success" collapsing a held team into the same result as a clean run is how
	 * a cron job ends up applying a plan that needed a person.
	 */
	private function status( ValidationResult $validation, array $summary ): string {
		foreach ( $validation->errors() as $error ) {
			if ( 'failed' === ( $error['class'] ?? 'failed' ) ) {
				return SyncPlan::FAILED;
			}
		}

		if ( ! $validation->is_valid() ) {
			return SyncPlan::BLOCKED;
		}

		if ( $validation->has_warnings() ) {
			return SyncPlan::WARNING;
		}

		$writes = $summary[ PlannedChange::CREATE ]
			+ $summary[ PlannedChange::UPDATE ]
			+ $summary[ PlannedChange::RENAME ]
			+ $summary[ PlannedChange::MOVE_DIVISION ]
			+ $summary[ PlannedChange::DEACTIVATE ];

		return 0 === $writes ? SyncPlan::NO_CHANGE : SyncPlan::PASS;
	}

	/**
	 * Fingerprint of the publishable result.
	 *
	 * Compared against the stored hash to answer "did anything a visitor can see
	 * actually change?". If it did not, there is nothing to write and no page
	 * cache to purge, however many rows the source returned.
	 *
	 * @param array<int,Team> $teams
	 */
	private function hash( array $teams ): string {
		ksort( $teams );

		$parts = array();
		foreach ( $teams as $id => $team ) {
			$parts[] = $id . ':' . $team->visible_hash();
		}

		return 'sha256:' . hash( 'sha256', implode( '|', $parts ) );
	}

	private function stop( string $run_id, EventConfig $config, ValidationResult $validation, string $planned_at ): SyncPlan {
		return new SyncPlan(
			run_id: $run_id,
			event_key: $config->event_key,
			status: $this->status( $validation, $this->summarise( array() ) ),
			changes: array(),
			summary: $this->summarise( array() ),
			errors: $validation->errors(),
			warnings: $validation->warnings(),
			source_meta: $validation->meta(),
			planned_at: $planned_at,
		);
	}
}
