<?php
/**
 * What may be read from a registration, and what may never be.
 *
 * The API key LeagueApps issues has wider scope than a public-facing site needs:
 * a registration row carries name, email, phone, address, date of birth, gender
 * and payment status. This plugin displays teams and divisions, so almost all of
 * that is dropped at the parse boundary, before anything is stored or logged.
 *
 * Two lists rather than one, deliberately. The allowlist is what we take; the
 * denylist is what must never be taken even if somebody widens the allowlist by
 * mistake. In an open-source plugin that will receive patches, the second list
 * is the one that matters.
 */

defined( 'ABSPATH' ) || defined( 'WP_CLI' ) || exit;

final class LAWP_Fields {

	/** Taken from every registration row. Nothing else is. */
	const ALLOW = array(
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
	 * are not, and no configuration turns them on.
	 */
	const DENY = array(
		'email', 'phone', 'mobilePhone',
		'address1', 'address2', 'city', 'state', 'zipCode', 'country',
		'birthDate', 'gender',
		'paymentStatus', 'amountPaid', 'totalAmountDue', 'outstandingBalance',
		'invoiceId', 'lastPaymentDate', 'waiverAcceptedTimestamp',
		'userId', 'userProfileId', 'photo',
	);

	/** Reduce one API row to the fields this plugin is allowed to see. */
	public static function reduce( array $row ) {
		$kept = array_intersect_key( $row, array_flip( self::ALLOW ) );

		// Belt and braces: if ALLOW and DENY ever overlap through an edit, DENY wins.
		foreach ( self::DENY as $blocked ) {
			unset( $kept[ $blocked ] );
		}
		return $kept;
	}

	/**
	 * True if an array contains anything on the denylist.
	 *
	 * Used by tests and by the sync before writing, so a regression fails loudly
	 * rather than quietly persisting somebody's address.
	 */
	public static function contains_denied( array $row ) {
		return (bool) array_intersect( array_keys( $row ), self::DENY );
	}
}
