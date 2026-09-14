<?php
/**
 * The division vocabulary for one Site: configuration, not code.
 *
 * This started life as a hardcoded list of A through E, Women's and Legends,
 * which is Softball Austin's vocabulary and nobody else's. A public plugin
 * cannot ship one league's divisions as a constant. So the map is data now:
 * discovery proposes what the Site actually returns, an operator confirms the
 * public label and order, and this object is built from that. presets/ holds
 * starting points for common shapes, never a default that applies silently.
 *
 * ALIASES ARE MATCHED LONGEST FIRST, and that is load-bearing rather than an
 * optimisation. "Legends D" contains "d"; "A/B" contains "a"; "Open C" contains
 * "c". Matching in declaration order meant hand-ordering the map so the
 * compound entries were tried before the single letters, which worked for one
 * league and silently broke for the next one to add an entry in the wrong
 * place. Longest-first makes the ordering a property of the data.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class DivisionMap {

	/** key => ['label' => string, 'order' => int, 'visible' => bool] */
	private array $divisions = array();

	/** alias => key, sorted longest alias first. */
	private array $aliases = array();

	/**
	 * @param array $config List of ['key','label','order','aliases'=>[],'visible'=>bool].
	 */
	public function __construct( array $config ) {
		$aliases = array();

		foreach ( $config as $entry ) {
			$key = isset( $entry['key'] ) ? (string) $entry['key'] : '';
			if ( '' === $key ) {
				continue;
			}

			$this->divisions[ $key ] = array(
				'label'   => (string) ( $entry['label'] ?? $key ),
				'order'   => (int) ( $entry['order'] ?? 0 ),
				'visible' => (bool) ( $entry['visible'] ?? true ),
			);

			// The key and the label are aliases of themselves. An operator who
			// adds "Masters" as a label should not also have to type it as an
			// alias for the source value "Masters" to match.
			$candidates = array_merge( array( $key, (string) ( $entry['label'] ?? '' ) ), (array) ( $entry['aliases'] ?? array() ) );

			foreach ( $candidates as $alias ) {
				$alias = self::fold( (string) $alias );
				if ( '' !== $alias ) {
					$aliases[ $alias ] = $key;
				}
			}
		}

		uksort( $aliases, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) ?: strcmp( $a, $b ) );
		$this->aliases = $aliases;
	}

	public function is_empty(): bool {
		return array() === $this->divisions;
	}

	public function has( string $key ): bool {
		return isset( $this->divisions[ $key ] );
	}

	public function label( string $key ): string {
		return $this->divisions[ $key ]['label'] ?? '';
	}

	public function order( string $key ): int {
		return $this->divisions[ $key ]['order'] ?? PHP_INT_MAX;
	}

	public function is_visible( string $key ): bool {
		return $this->divisions[ $key ]['visible'] ?? false;
	}

	/** Configured keys in display order. */
	public function keys_in_order(): array {
		$keys = array_keys( $this->divisions );
		usort( $keys, fn( string $a, string $b ): int => $this->order( $a ) <=> $this->order( $b ) ?: strcmp( $a, $b ) );
		return $keys;
	}

	/**
	 * Find a division inside a piece of text.
	 *
	 * Word-boundary matched, not substring. "2022 Open End of Season Tournament"
	 * contains "open e" and was read as E Division until a test caught it. The
	 * boundary look-arounds exclude letters and digits but not "/" or "-", so
	 * "a/b" and "legends-d" still match.
	 */
	public function find_in( string $text ): ?string {
		$haystack = self::fold( $text );
		if ( '' === $haystack ) {
			return null;
		}

		/*
		 * EARLIEST MATCH WINS, then longest. Both halves were arrived at by being
		 * wrong first.
		 *
		 * Longest-alias-first came first, so that "Legends D" was not read as
		 * "D". That held until a real program called "Legends D Division"
		 * appeared: there "d division" is ten characters and "legends d" is nine,
		 * the longer alias won, and Legends teams were about to be filed under D.
		 * Invisible, because no Legends team had registered yet.
		 *
		 * Position is the better rule anyway. A division name is what the program
		 * is called, near the front, rather than a fragment further along. Length
		 * still breaks ties, so "open d" beats "d" where both start together.
		 */
		$best_key = null;
		$best_at  = PHP_INT_MAX;
		$best_len = 0;

		foreach ( $this->aliases as $alias => $key ) {
			$pattern = '/(?<![a-z0-9])' . preg_quote( $alias, '/' ) . '(?![a-z0-9])/';

			if ( 1 !== preg_match( $pattern, $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$at  = (int) $m[0][1];
			$len = strlen( $alias );

			if ( $at < $best_at || ( $at === $best_at && $len > $best_len ) ) {
				$best_key = $key;
				$best_at  = $at;
				$best_len = $len;
			}
		}

		return $best_key;
	}

	/**
	 * Lower-case, straighten quotes, collapse whitespace.
	 *
	 * The apostrophe matters more than it looks. A Site may return "Women's",
	 * "Women\u{2019}s" or "Womens" for the same division, sometimes all three
	 * across a decade of programs, and an operator typing an alias will use
	 * whichever their keyboard produces.
	 */
	public static function fold( string $text ): string {
		$folded = str_replace( array( "\u{2019}", "\u{2018}", '`', "\u{00B4}" ), "'", $text );
		$folded = strtolower( trim( $folded ) );
		return (string) preg_replace( '/\s+/', ' ', $folded );
	}
}
