<?php
/**
 * Works out which division a registration belongs to.
 *
 * LeagueApps supports two ways of organising divisions, and a site may use
 * either. This handles both, in order of authority:
 *
 *   1. The `division` field, when the site uses divisions INSIDE one program.
 *      This is explicit and set by the organiser, so it always wins.
 *   2. The program name, when the site uses a SEPARATE PROGRAM per division.
 *      This is inference and is only used when the field is empty.
 *
 * Never assume one model. Measured across two live sites the field is populated
 * 0% and 11% of the time, because both use separate programs; a site configured
 * with in-program divisions will be the opposite.
 *
 * Names vary a lot across a decade and between event types:
 *
 *   2026 Summer Classic (C Division)      tournament, division in brackets
 *   Open D Division Fall 2026            league, season last
 *   2019 Spring Open D Division          league, season first
 *   Spring 2018 Womens Division          no apostrophe
 *
 * So this matches on the division token wherever it appears, rather than
 * assuming a position.
 */

defined( 'ABSPATH' ) || defined( 'WP_CLI' ) || exit;

final class LAWP_Divisions {

	/** Canonical divisions, in display order. Aliases are matched case-insensitively. */
	private static $map = array(
		'ab'        => array( 'label' => 'A/B Division',       'order' => 10, 'match' => array( 'a/b', 'a and b', 'ab division' ) ),
		'a'         => array( 'label' => 'A Division',         'order' => 20, 'match' => array( 'open a', 'a division', 'division a', 'a' ) ),
		'b'         => array( 'label' => 'B Division',         'order' => 30, 'match' => array( 'open b', 'b division', 'division b', 'b' ) ),
		'c'         => array( 'label' => 'C Division',         'order' => 40, 'match' => array( 'open c', 'c division', 'division c', 'c' ) ),
		'd'         => array( 'label' => 'D Division',         'order' => 50, 'match' => array( 'open d', 'd division', 'division d', 'd' ) ),
		'e'         => array( 'label' => 'E Division',         'order' => 60, 'match' => array( 'open e', 'e division', 'division e', 'e' ) ),
		'womens'    => array( 'label' => "Women's Division",   'order' => 70, 'match' => array( "women's", 'womens', 'women' ) ),
		/*
		 * One Legends division, not two.
		 *
		 * Source data is inconsistent: programs appear as "Legends D Division",
		 * "Legends", and historically "Masters", which was the old name for the
		 * same thing. In practice the league calls it Legends, so all four
		 * variants collapse to one division rather than splitting a decade of
		 * history across near-duplicate labels.
		 *
		 * If a site genuinely runs Legends AND Legends D as separate
		 * competitions, split this entry. Ours does not.
		 */
		'legends'   => array( 'label' => 'Legends Division',   'order' => 80, 'match' => array( 'legends d', 'legend d', 'legends-d', 'legends', 'masters d', 'masters', 'master d', 'master' ) ),
	);

	/**
	 * @param string $program_name   The program's name.
	 * @param string $division_field The registration's `division` value, if any.
	 * @return array{key:string,label:string,order:int,source:string}|null
	 */
	public static function detect( $program_name, $division_field = '' ) {
		// An explicit division beats anything parsed out of a name.
		$explicit = trim( (string) $division_field );
		if ( '' !== $explicit ) {
			$hit = self::match_text( $explicit );
			if ( $hit ) { $hit['source'] = 'division_field'; return $hit; }

			// Set, but not a division we know. Do not fall back to the program
			// name: the organiser said something specific and guessing past it
			// would put the team somewhere they did not choose.
			return null;
		}

		$hit = self::match_text( (string) $program_name );
		if ( $hit ) { $hit['source'] = 'program_name'; }
		return $hit;
	}

	private static function match_text( $text ) {
		$n = strtolower( $text );
		$n = str_replace( array( '’', '`' ), "'", $n );

		// Legends D before both 'legends' and 'd', or it matches the wrong one.
		foreach ( array( 'ab', 'womens', 'legends' ) as $key ) {
			if ( self::hit( $n, self::$map[ $key ]['match'] ) ) { return self::out( $key ); }
		}
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $key ) {
			if ( self::hit( $n, self::$map[ $key ]['match'] ) ) { return self::out( $key ); }
		}
		return null;
	}

	/** Divisions present in a set of rows, in display order. */
	public static function order_keys( array $keys ) {
		$known = array_filter( $keys, function ( $k ) { return isset( self::$map[ $k ] ); } );
		usort( $known, function ( $a, $b ) { return self::$map[ $a ]['order'] <=> self::$map[ $b ]['order']; } );
		return $known;
	}

	public static function label( $key ) {
		return self::$map[ $key ]['label'] ?? '';
	}

	/**
	 * Match on word boundaries, not substrings.
	 *
	 * "2022 Open End of Season Tournament" contains "open e" and was being read
	 * as E Division. Found by a test rather than in production, which is the
	 * argument for the test.
	 */
	private static function hit( $haystack, array $needles ) {
		foreach ( $needles as $n ) {
			$pattern = '/(?<![a-z0-9])' . preg_quote( $n, '/' ) . '(?![a-z0-9])/i';
			if ( preg_match( $pattern, $haystack ) ) { return true; }
		}
		return false;
	}

	private static function out( $key ) {
		return array( 'key' => $key, 'label' => self::$map[ $key ]['label'], 'order' => self::$map[ $key ]['order'] );
	}
}
