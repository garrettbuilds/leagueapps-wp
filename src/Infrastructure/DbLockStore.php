<?php
/**
 * One sync per event at a time, enforced by a unique index.
 *
 * The acquire is a single INSERT that either succeeds or violates the unique key.
 * A read-then-write would have a window between the two in which a second process
 * reads "free" and both proceed.
 */

declare( strict_types=1 );

namespace LeagueAppsWP\Infrastructure;

use LeagueAppsWP\Contracts\ClockInterface;
use LeagueAppsWP\Contracts\LockStoreInterface;

final class DbLockStore implements LockStoreInterface {

	public function __construct(
		private readonly \wpdb $db,
		private readonly ClockInterface $clock,
	) {}

	public function acquire( string $name, string $owner, int $ttl_seconds ): bool {
		$now     = $this->clock->now();
		$now_sql = $now->format( 'Y-m-d H:i:s' );
		$expires = $now->modify( '+' . max( 1, $ttl_seconds ) . ' seconds' )->format( 'Y-m-d H:i:s' );

		/*
		 * Take the lock if it is free, or if the existing one has expired.
		 *
		 * An expired lock is taken over rather than waited on, because the usual
		 * reason for one is a process that was killed mid-run and will never come
		 * back to release it. The TTL is what makes that safe: it must be longer
		 * than the slowest legitimate sync, or a long run gets its lock stolen
		 * while it is still working.
		 */
		$rows = $this->db->query(
			$this->db->prepare(
				'INSERT INTO ' . Schema::locks_table() . ' (lock_name, owner, acquired_at, expires_at, heartbeat_at)
				VALUES (%s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					owner = IF(expires_at < %s, VALUES(owner), owner),
					acquired_at = IF(expires_at < %s, VALUES(acquired_at), acquired_at),
					heartbeat_at = IF(expires_at < %s, VALUES(heartbeat_at), heartbeat_at),
					expires_at = IF(expires_at < %s, VALUES(expires_at), expires_at)',
				$name, $owner, $now_sql, $expires, $now_sql,
				$now_sql, $now_sql, $now_sql, $now_sql
			)
		);

		if ( false === $rows ) {
			return false;
		}

		// The INSERT reports success either way, so confirm we are the holder.
		return $owner === $this->owner_of( $name );
	}

	public function release( string $name, string $owner ): bool {
		$deleted = $this->db->delete(
			Schema::locks_table(),
			array( 'lock_name' => $name, 'owner' => $owner ),
			array( '%s', '%s' )
		);

		return (bool) $deleted;
	}

	public function heartbeat( string $name, string $owner ): bool {
		$updated = $this->db->update(
			Schema::locks_table(),
			array( 'heartbeat_at' => $this->clock->now()->format( 'Y-m-d H:i:s' ) ),
			array( 'lock_name' => $name, 'owner' => $owner ),
			array( '%s' ),
			array( '%s', '%s' )
		);

		return (bool) $updated;
	}

	public function owner_of( string $name ): ?string {
		$owner = $this->db->get_var(
			$this->db->prepare(
				'SELECT owner FROM ' . Schema::locks_table() . ' WHERE lock_name = %s AND expires_at >= %s',
				$name,
				$this->clock->now()->format( 'Y-m-d H:i:s' )
			)
		);

		return null !== $owner ? (string) $owner : null;
	}
}
