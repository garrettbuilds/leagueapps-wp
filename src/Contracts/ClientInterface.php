<?php
/**
 * The only way the pipeline reaches LeagueApps.
 *
 * One method, no method parameter, no URL parameter. Adding a write to this
 * plugin would mean adding a visibly named method to this interface and to every
 * implementation of it, which is the point: there is no general
 * request( $method, $url ) for a patch to quietly pass "POST" to.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Contracts;

use LeagueAppsWP\Domain\SourceResult;

interface ClientInterface {

	/**
	 * Read registrations for a Site, from a watermark.
	 *
	 * Returns a SourceResult that always reports whether the read finished,
	 * never a bare array. A caller cannot then accidentally treat a partial
	 * read as the full picture, because there is no shape in which it looks
	 * like one.
	 *
	 * @param int $site_id  LeagueApps Site id.
	 * @param int $since_ms Millisecond epoch watermark; 0 for everything.
	 */
	public function registrations( int $site_id, int $since_ms = 0 ): SourceResult;

	/** Programs for a Site. Used by discovery and by source-identity checks. */
	public function programs( int $site_id, int $since_ms = 0 ): SourceResult;
}
