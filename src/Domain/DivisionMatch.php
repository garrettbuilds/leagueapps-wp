<?php
/**
 * The result of asking "which division is this?".
 *
 * Three outcomes, and the third is the one that matters: a value that was set
 * but is not recognised. That is not the same as no value at all, and it must
 * never be treated as one. The organiser said something specific; guessing past
 * it would publish a team in a division nobody put it in.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class DivisionMatch {

	public const KNOWN       = 'known';
	public const UNASSIGNED  = 'unassigned';
	public const UNRECOGNISED = 'unrecognised';

	private function __construct(
		public readonly string $outcome,
		public readonly ?string $key,
		public readonly ?string $label,
		public readonly int $order,
		public readonly string $source_value,
		/** division_field | program_name | none */
		public readonly string $matched_on,
	) {}

	public static function known( string $key, string $label, int $order, string $source_value, string $matched_on ): self {
		return new self( self::KNOWN, $key, $label, $order, $source_value, $matched_on );
	}

	/** No division value anywhere, and no program name that implies one. */
	public static function unassigned(): self {
		return new self( self::UNASSIGNED, null, null, PHP_INT_MAX, '', 'none' );
	}

	/** A value was set. We do not know it. Hold, do not guess. */
	public static function unrecognised( string $source_value, string $matched_on ): self {
		return new self( self::UNRECOGNISED, null, null, PHP_INT_MAX, $source_value, $matched_on );
	}

	public function is_known(): bool {
		return self::KNOWN === $this->outcome;
	}
}
