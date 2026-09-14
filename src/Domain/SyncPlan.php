<?php
/**
 * An immutable description of what a sync would do. It does nothing itself.
 *
 * The planner returns one of these and the applier consumes one. That split is
 * the whole dry-run guarantee: a dry run is the same code path as a live run
 * with the last step omitted, so there is no separate "preview" logic to drift
 * out of agreement with the real thing.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class SyncPlan {

	/** Outcomes, mapped to exit codes by Reporter. */
	public const PASS      = 'pass';
	public const NO_CHANGE = 'no_change';
	public const WARNING   = 'warning';
	public const BLOCKED   = 'blocked';
	public const FAILED    = 'failed';

	public function __construct(
		public readonly string $run_id,
		public readonly string $event_key,
		public readonly string $status,
		/** @var PlannedChange[] */
		public readonly array $changes,
		public readonly array $summary,
		/** @var array<int,array{code:string,message:string,count?:int}> */
		public readonly array $errors = array(),
		public readonly array $warnings = array(),
		public readonly string $source_hash = '',
		public readonly array $division_report = array(),
		public readonly array $source_meta = array(),
		public readonly string $planned_at = '',
	) {}

	/**
	 * Whether an unattended apply may proceed.
	 *
	 * Warnings block it too. A held team is a question for a person, and a cron
	 * job cannot answer it; an operator can still apply a warning plan
	 * deliberately.
	 */
	public function safe_to_apply(): bool {
		return in_array( $this->status, array( self::PASS, self::NO_CHANGE ), true );
	}

	public function has_error_code( string $code ): bool {
		foreach ( $this->errors as $error ) {
			if ( $code === ( $error['code'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	public function has_warning_code( string $code ): bool {
		foreach ( $this->warnings as $warning ) {
			if ( $code === ( $warning['code'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	public function count( string $action ): int {
		return (int) ( $this->summary[ $action ] ?? 0 );
	}

	/** @return PlannedChange[] */
	public function changes_of( string $action ): array {
		return array_values( array_filter( $this->changes, static fn( PlannedChange $c ): bool => $c->action === $action ) );
	}

	/** @return PlannedChange[] */
	public function writes(): array {
		return array_values( array_filter( $this->changes, static fn( PlannedChange $c ): bool => $c->writes() ) );
	}

	public function exit_code(): int {
		return match ( $this->status ) {
			self::PASS, self::NO_CHANGE => 0,
			self::WARNING               => 2,
			self::BLOCKED               => 3,
			default                     => 1,
		};
	}
}
