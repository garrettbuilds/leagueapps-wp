<?php
/**
 * What may be read from a registration, and what may never be.
 *
 * The API key LeagueApps issues has wider scope than a public-facing site needs:
 * a registration row carries name, email, phone, address, date of birth, gender
 * and payment status. This plugin publishes teams and divisions, so almost all of
 * that is dropped at the parse boundary, before anything is stored, rendered or
 * logged.
 *
 * Two lists rather than one, deliberately. ALLOW is what we take. DENY is what
 * must never be taken even if somebody widens ALLOW by mistake. In an
 * open-source plugin that will receive patches, the second list is the one that
 * matters, and contains_denied() is what makes a mistaken patch fail a test
 * instead of quietly persisting somebody's address.
 *
 * Honest limitation: team names live only on registration rows, so a response
 * carries personal data in transit for the moment before reduce() drops it. It
 * is never stored and never logged. "We never read it" would be inaccurate.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Domain;

final class FieldPolicy {

	/** Taken from every registration row. Nothing else is. */
	public const ALLOW = array(
		'programId', 'programName', 'programState',
		'teamId', 'team',
		'division', 'season', 'registrationStatus',
		'role', 'isStaff',
		'firstName', 'lastName',
	);

	/**
	 * Never stored, never rendered, never logged.
	 *
	 * Names are permitted above because a captain or coach credit is ordinary
	 * public information on a team listing. Contact details and date of birth
	 * are not, and no configuration option turns them on.
	 */
	public const DENY = array(
		'email', 'phone', 'mobilePhone',
		'address1', 'address2', 'city', 'state', 'zipCode', 'country',
		'birthDate', 'gender',
		'paymentStatus', 'amountPaid', 'totalAmountDue', 'outstandingBalance',
		'invoiceId', 'lastPaymentDate', 'waiverAcceptedTimestamp',
		'userId', 'userProfileId', 'photo',
	);

	public static function reduce( array $row ): array {
		$kept = array_intersect_key( $row, array_flip( self::ALLOW ) );

		// Belt and braces: if ALLOW and DENY ever overlap through an edit, DENY wins.
		foreach ( self::DENY as $blocked ) {
			unset( $kept[ $blocked ] );
		}

		return $kept;
	}

	public static function contains_denied( array $row ): bool {
		return array() !== array_intersect( array_keys( $row ), self::DENY );
	}
}
