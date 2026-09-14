<?php
/**
 * Turns the city on a registration into the metro a reader recognises.
 *
 * Two reasons, and the second is the important one.
 *
 * IT READS BETTER. A tournament visitor scanning a team list wants to know a
 * team travelled from Dallas. "Frisco" means nothing unless you know the area,
 * and "Chesterfield" tells almost nobody that the team is from St. Louis.
 *
 * IT IDENTIFIES LESS. The city on these records is the REGISTRANT's home city,
 * not the team's. Publishing "Frisco, TX" narrows a named person to a couple of
 * hundred thousand people; publishing "Dallas-Fort Worth" narrows them to eight
 * million. The coarser label is both more useful and less exposing, which is a
 * rare combination and worth taking.
 *
 * An unmatched city falls through as written. That is the least surprising
 * behaviour, and it is also the more identifying one, so a league publishing
 * locations should expect to add entries as unfamiliar suburbs appear.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class MetroMap {

	/** normalised city => metro label */
	private array $cities = array();

	/**
	 * @param array $config metro label => list of city names that belong to it.
	 */
	public function __construct( array $config ) {
		foreach ( $config as $metro => $cities ) {
			foreach ( (array) $cities as $city ) {
				$key = self::fold( (string) $city );

				if ( '' !== $key ) {
					$this->cities[ $key ] = (string) $metro;
				}
			}
		}
	}

	/**
	 * @param string $city  As the source wrote it.
	 * @param string $state Two-letter code, in any casing.
	 * @return string "Dallas-Fort Worth, TX", "Austin, TX", or '' when there is
	 *                nothing worth showing.
	 */
	public function label( string $city, string $state = '' ): string {
		$city  = trim( $city );
		$state = strtoupper( trim( $state ) );

		// The source is inconsistent about this: TX, Tx and mN all appear on the
		// same tournament. A two-letter code is upper case or it is wrong.
		if ( 2 !== strlen( $state ) || 1 !== preg_match( '/^[A-Z]{2}$/', $state ) ) {
			$state = '';
		}

		if ( '' === $city ) {
			return '';
		}

		$metro = $this->cities[ self::fold( $city ) ] ?? NameCase::person( $city );

		return '' !== $state ? $metro . ', ' . $state : $metro;
	}

	public function knows( string $city ): bool {
		return isset( $this->cities[ self::fold( $city ) ] );
	}

	public function count(): int {
		return count( array_unique( array_values( $this->cities ) ) );
	}

	/**
	 * Lower case, no punctuation, collapsed spaces.
	 *
	 * "St Paul", "St. Paul" and "SAINT PAUL" are one place, and a form lets
	 * people write all three.
	 */
	public static function fold( string $city ): string {
		$c = strtolower( trim( $city ) );
		$c = str_replace( array( 'saint ', 'ste ' ), 'st ', $c );
		$c = (string) preg_replace( '/[^a-z0-9 ]+/', '', $c );
		$c = (string) preg_replace( '/\s+/', ' ', $c );

		return trim( $c );
	}
}
