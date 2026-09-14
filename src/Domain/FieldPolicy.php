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
		/*
		 * City and state are ADDRESS COMPONENTS, and they are here deliberately.
		 *
		 * On a tournament they answer "where is this team travelling from",
		 * which is ordinary public information that most events print. They are
		 * also, literally, two fields out of somebody's home address: on the site
		 * this was built for, `city` sits between `address1` and `zipCode`, and
		 * the value is the REGISTRANT's city rather than the team's.
		 *
		 * So they are taken, and rendering them is off unless a site turns it on.
		 * The rest of the address - street, postcode, phone, email - stays denied
		 * and has no setting.
		 *
		 * Worth knowing before enabling: showing a location NEXT TO a named
		 * manager tells the world where that particular person lives. Showing the
		 * location alone does not.
		 */
		'city', 'state',
		'division', 'season', 'registrationStatus',
		'role', 'isStaff',
		'firstName', 'lastName',
	);

	/**
	 * Read to make a decision. NEVER stored, rendered or logged.
	 *
	 * A third category exists because the second one was not quite true.
	 *
	 * A league asked that only teams which have paid appear publicly. Deciding
	 * that means reading paymentStatus, and paymentStatus was on the denylist -
	 * correctly, because publishing who has and has not paid would be a small
	 * humiliation printed on a public page.
	 *
	 * But reading a field to decide whether to show a ROW is not the same act as
	 * storing or publishing it. Collapsing the two left only bad options: publish
	 * unpaid teams, or widen the denylist and lose the guarantee.
	 *
	 * So: reduce() keeps these, the normaliser reads them, and Team has no
	 * property that can hold one. The guarantee is structural rather than
	 * remembered - there is no field to put it in.
	 *
	 * ONLY paymentStatus. Not amountPaid, not outstandingBalance, not invoiceId,
	 * not totalAmountDue, not lastPaymentDate. Those answer "how much" and "when",
	 * which no visibility decision needs, and they stay denied.
	 */
	public const DECIDE_ONLY = array( 'paymentStatus' );

	/**
	 * Never stored, never rendered, never logged.
	 *
	 * Names are permitted above because a captain or coach credit is ordinary
	 * public information on a team listing. Contact details and date of birth
	 * are not, and no configuration option turns them on.
	 */
	public const DENY = array(
		'email', 'phone', 'mobilePhone',
		'address1', 'address2', 'zipCode', 'country',
		'birthDate', 'gender',
		'amountPaid', 'totalAmountDue', 'outstandingBalance',
		'invoiceId', 'lastPaymentDate', 'waiverAcceptedTimestamp',
		'userId', 'userProfileId', 'photo',
	);

	public static function reduce( array $row ): array {
		$kept = array_intersect_key( $row, array_flip( array_merge( self::ALLOW, self::DECIDE_ONLY ) ) );

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
