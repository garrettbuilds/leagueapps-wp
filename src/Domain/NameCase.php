<?php
/**
 * Tidies a SHOUTED name without rewriting somebody's chosen spelling.
 *
 * A registration form produces "Mathew HALL", because people fill surname fields
 * in capitals. That is a typing artefact, not a decision, and printing it on a
 * public page looks like the site is shouting at a volunteer.
 *
 * WHAT IT DELIBERATELY WILL NOT TOUCH
 *
 * A word that is not entirely uppercase is left exactly as written. "McDonald",
 * "van der Berg", "d'Angelo" and "JoAnne" all survive, because somebody typed
 * them that way on purpose and naive title-casing would produce "Mcdonald".
 *
 * A word of three characters or fewer is left alone, because that is where
 * initials and abbreviations live: "JR", "II", "MD".
 *
 * Anything containing a digit is left alone: "F5" is a name, not a shout.
 *
 * THIS IS FOR PEOPLE, NOT FOR TEAMS
 *
 * A team that writes itself in capitals has made a branding choice and it is
 * theirs to make. Teams are normalised only if a site opts in.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class NameCase {

	/**
	 * Words that stay capitalised, and why there is no length rule.
	 *
	 * The first version skipped any word of three characters or fewer, to protect
	 * JR and II. It also skipped Ian, Ana, Bob and Kim, so "IAN SMITH" came out
	 * as "IAN Smith" - half shouted, which looks worse than leaving it alone.
	 *
	 * A named list is the honest instrument. It says what is being protected
	 * instead of hoping length correlates with it.
	 */
	private const KEEP = array(
		'JR', 'SR', 'II', 'III', 'IV', 'V', 'VI',
		'MD', 'PHD', 'DDS', 'DVM', 'ESQ', 'RN', 'MBA',
	);

	/** Prefixes whose following letter is capitalised too. */
	private const PREFIXES = array( 'mc', 'mac', "o'", "d'", "l'" );

	/** Particles that stay lower case inside a longer name. */
	private const PARTICLES = array( 'van', 'von', 'der', 'den', 'de', 'di', 'da', 'del', 'della', 'la', 'le', 'du', 'ter', 'bin', 'al' );

	public static function person( string $name ): string {
		$words = preg_split( '/(\s+)/u', trim( $name ), -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $words ) ) {
			return $name;
		}

		$out   = array();
		$index = 0;

		foreach ( $words as $word ) {
			if ( '' === trim( $word ) ) {
				$out[] = $word;
				continue;
			}

			$out[] = self::word( $word, 0 === $index );
			++$index;
		}

		return implode( '', $out );
	}

	private static function word( string $word, bool $is_first ): string {
		// Mixed case is a decision. Leave it.
		if ( $word !== self::upper( $word ) ) {
			return $word;
		}

		if ( 1 === preg_match( '/\d/u', $word ) ) {
			return $word;
		}

		// A lone letter is an initial: "J R Smith".
		if ( 1 === self::length( rtrim( $word, '.' ) ) ) {
			return $word;
		}

		if ( in_array( rtrim( $word, '.' ), self::KEEP, true ) ) {
			return $word;
		}

		$lower = self::lower( $word );

		/*
		 * Particles first, before anything else can skip them. They are short,
		 * which is exactly why the old length rule never reached them and
		 * "PIET VAN DER BERG" came out as "Piet VAN DER Berg".
		 */
		if ( ! $is_first && in_array( $lower, self::PARTICLES, true ) ) {
			return $lower;
		}

		// Hyphens and apostrophes both start a new capital: SMITH-JONES becomes
		// Smith-Jones, O'BRIEN becomes O'Brien.
		$cased = preg_replace_callback(
			'/(^|[-\x{2019}\'])([\p{L}])/u',
			static fn( array $m ): string => $m[1] . self::upper( $m[2] ),
			$lower
		);

		$cased = is_string( $cased ) ? $cased : $word;

		foreach ( self::PREFIXES as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) && self::length( $word ) > strlen( $prefix ) + 1 ) {
				$head = self::upper( self::substr( $cased, 0, 1 ) ) . self::substr( $cased, 1, strlen( $prefix ) - 1 );
				$tail = self::substr( $cased, strlen( $prefix ) );
				$cased = $head . self::upper( self::substr( $tail, 0, 1 ) ) . self::substr( $tail, 1 );
				break;
			}
		}

		return $cased;
	}

	private static function upper( string $s ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	private static function length( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	private static function substr( string $s, int $start, ?int $len = null ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, $start, $len, 'UTF-8' ) : substr( $s, $start, $len ?? PHP_INT_MAX );
	}
}
