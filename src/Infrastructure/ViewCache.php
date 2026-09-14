<?php
/**
 * Disposable acceleration in front of the durable table.
 *
 * Two rules, and they are the whole class:
 *
 * 1. A miss is normal. Redis restarts, object caches evict, a page loads on a
 *    node that has never seen this key. Every read falls back to the table.
 * 2. A failure is never cached. An empty result from a failed read, stored here
 *    and then served to the page cache and then to a CDN, is how a working site
 *    starts telling visitors there are no teams.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

final class ViewCache {

	private const GROUP = 'lawp';

	/** Bump when the cached SHAPE changes, so old entries cannot be misread. */
	private const SCHEMA = 'v1';

	private const TTL = 900;

	/**
	 * The generation is in the key, not just deleted on change.
	 *
	 * A delete can fail silently on one node of a multi-server install, and a
	 * node still holding generation 42 would serve it until its TTL expired.
	 * Putting the generation in the key makes the old entry unreachable instead
	 * of merely unwanted.
	 */
	public static function key( string $event_key, string $resource, int $generation ): string {
		return sprintf( 'lawp:%s:%s:%s:g%d', self::SCHEMA, $event_key, $resource, $generation );
	}

	/**
	 * @param callable():mixed $build Called on a miss. Must return the value, or
	 *                                null if it could not build one.
	 */
	public static function remember( string $event_key, string $resource, int $generation, callable $build ): mixed {
		$key    = self::key( $event_key, $resource, $generation );
		$cached = wp_cache_get( $key, self::GROUP );

		// Strict, because an empty-but-valid division list and a cache miss are
		// different states and empty() cannot tell them apart.
		if ( false !== $cached ) {
			return $cached;
		}

		$value = $build();

		if ( null === $value ) {
			return null;
		}

		wp_cache_set( $key, $value, self::GROUP, self::TTL );

		return $value;
	}

	public static function forget( string $event_key, string $resource, int $generation ): void {
		wp_cache_delete( self::key( $event_key, $resource, $generation ), self::GROUP );
	}
}
