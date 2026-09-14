<?php
/**
 * One team, as this plugin is allowed to know it.
 *
 * Immutable. The planner compares these and builds a plan out of them; nothing
 * downstream may edit one in place, because a plan that can be mutated after it
 * was validated is not a plan.
 *
 * Identity is event_key + source_team_id and nothing else. Names change, casing
 * varies between rows for the same team, and two teams may legitimately share a
 * name. Keying on the name splits one team in two or merges two into one.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class Team {

	public function __construct(
		public readonly string $event_key,
		public readonly int $source_team_id,
		public readonly string $name,
		public readonly ?string $division_key,
		public readonly ?string $division_label,
		public readonly string $source_division_value = '',
		public readonly int $roster_count = 0,
		public readonly string $captain = '',
		public readonly ?int $program_id = null,
		public readonly string $program_name = '',
		public readonly string $display_name_override = '',
		/** Already formatted for display, e.g. "Austin, TX". Never a full address. */
		public readonly string $location = '',
	) {}

	/** What the public page shows: an operator override wins over the source name. */
	public function display_name(): string {
		return '' !== $this->display_name_override ? $this->display_name_override : $this->name;
	}

	/**
	 * Fingerprint of everything a visitor can see.
	 *
	 * Used to tell UPDATE from NO_CHANGE. Deliberately excludes program_id and
	 * the raw source division value: a site that renames a program mid-season
	 * has not changed anything on the page, and rewriting every row for that
	 * would churn the cache and reset every last-changed timestamp.
	 */
	public function visible_hash(): string {
		return hash( 'sha256', implode( "\x1f", array(
			$this->display_name(),
			(string) $this->division_key,
			$this->location,
			(string) $this->roster_count,
			$this->captain,
		) ) );
	}

	public function with( array $changes ): self {
		return new self(
			$changes['event_key'] ?? $this->event_key,
			$changes['source_team_id'] ?? $this->source_team_id,
			$changes['name'] ?? $this->name,
			array_key_exists( 'division_key', $changes ) ? $changes['division_key'] : $this->division_key,
			array_key_exists( 'division_label', $changes ) ? $changes['division_label'] : $this->division_label,
			$changes['source_division_value'] ?? $this->source_division_value,
			$changes['roster_count'] ?? $this->roster_count,
			$changes['captain'] ?? $this->captain,
			array_key_exists( 'program_id', $changes ) ? $changes['program_id'] : $this->program_id,
			$changes['program_name'] ?? $this->program_name,
			$changes['display_name_override'] ?? $this->display_name_override,
			$changes['location'] ?? $this->location,
		);
	}
}
