# Changelog

## 0.2.0

Restructured around a plan/apply split, with a test suite.

### Added

- `SyncPlanner` builds an immutable `SyncPlan`; `SyncApplier` consumes one. A dry
  run is the planner plus a report, so there is no second preview implementation
  to drift out of agreement with the real thing.
- Dry run is now the default. Applying needs `--mode=apply` and a `--confirm`
  string naming the event.
- Five outcomes rather than two: `PASS`, `NO_CHANGE`, `WARNING`, `BLOCKED`,
  `FAILED`, mapped to exit codes 0/0/2/3/1. A warning blocks an unattended apply.
- Mass-removal circuit breaker, on both a count and a percentage.
- Four unknown-division policies: hold, show pending, hide, fail.
- Source-identity checking against configured programs.
- Durable tables for teams, run history and locks. Locks are rows, not transients.
- Cache generation numbers, so a stale key becomes unreachable rather than needing
  to be found and deleted.
- `uninstall.php`, which deletes nothing unless it was switched on beforehand.
- 99 unit tests that need no WordPress, no database and no network.

### Changed

- **The division map is configuration, not code.** It shipped as a hardcoded list
  of one league's divisions. `presets/` now holds starting points that nothing
  loads unless an operator picks one.
- **Aliases match longest first.** The map previously had to be hand-ordered so
  compound entries came before single letters, which worked for one league and
  broke the first time an entry was added in the wrong place.
- The reader reports *how* a read ended, not just whether it errored. Only
  `exhausted` counts as complete, and only a complete read may plan a removal.
- A non-JSON body is now a distinct failure. A 200 carrying HTML is not a read.
- Namespaced under `LeagueAppsWP\`, PSR-4, loaded by a four-line autoloader so
  `vendor/` is not shipped.

### Removed

- Two guards that mutation testing proved were dead code: a deactivation gate in
  the planner and a blocked/failed re-check in the applier. Both read like defence
  in depth and neither was reachable. Replaced by a property asserted across every
  failure mode, through the applier, which is the live guard.

### Fixed

- Requires PHP 8.1, up from 7.4, for readonly properties. A plan that can be
  mutated after it was validated is not a plan.

## 0.1.0

First release. CLI only.
