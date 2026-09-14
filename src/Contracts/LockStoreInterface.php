<?php
/**
 * One sync per event at a time.
 *
 * Deliberately not a transient. Object caches evict, and an evicted lock is an
 * absent lock; on a multi-server install a transient may not even be shared. A
 * durable row is the only thing that answers "is another run working?" the same
 * way from every process.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Contracts;

interface LockStoreInterface {

	/** True if this owner now holds the lock. False if somebody else does. */
	public function acquire( string $name, string $owner, int $ttl_seconds ): bool;

	/** Only the owner may release. A run must not free a lock it does not hold. */
	public function release( string $name, string $owner ): bool;

	public function heartbeat( string $name, string $owner ): bool;

	public function owner_of( string $name ): ?string;
}
