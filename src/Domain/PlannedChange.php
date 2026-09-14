<?php
/**
 * One thing that would happen to the public cache.
 *
 * A change always carries both sides. "Before" alone cannot be reviewed and
 * "after" alone cannot be undone, and an operator approving a plan is entitled
 * to see what is being replaced.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class PlannedChange {

	public const CREATE          = 'create';
	public const UPDATE          = 'update';
	public const RENAME          = 'rename';
	public const MOVE_DIVISION   = 'move_division';
	public const DEACTIVATE      = 'deactivate';
	public const NO_CHANGE       = 'no_change';
	public const HOLD_FOR_REVIEW = 'hold_for_review';
	public const HIDE_BY_POLICY  = 'hide_by_policy';
	public const EXCLUDE_INVALID = 'exclude_invalid';

	public function __construct(
		public readonly string $action,
		public readonly ?Team $before,
		public readonly ?Team $after,
		public readonly array $reasons = array(),
	) {}

	/** Actions that write to the cache. Everything else is a no-op or a report line. */
	public const WRITES = array( self::CREATE, self::UPDATE, self::RENAME, self::MOVE_DIVISION, self::DEACTIVATE );

	public function writes(): bool {
		return in_array( $this->action, self::WRITES, true );
	}

	public function source_team_id(): ?int {
		return $this->after->source_team_id ?? $this->before->source_team_id ?? null;
	}

	public function label(): string {
		return $this->after->display_name() ?? $this->before->display_name() ?? '';
	}
}
