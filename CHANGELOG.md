# Changelog

## 0.2.0

Restructured around a plan/apply split, with a test suite, and a front end.

### Added

- **A Teams block and a matching shortcode**, sharing one renderer. Core blocks
  and core markup, so it renders the same under any theme or builder. No build
  step: the editor script is plain JavaScript, and the file in the repo is the
  file that runs.
- Editor controls appear only for capabilities measured from the cache. A
  tournament where only managers register does not get a player-count toggle that
  would print a column of 1s.
- Three distinct empty states, none of which prints a technical reason.
- View cache keyed by event generation, so a stale entry is unreachable rather
  than merely unwanted.

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

## 0.3.0

An admin screen, so the plugin is usable by whoever inherits the site.

### Added

- **LeagueApps** menu: connection status, per-event team and division counts,
  which divisions are configured but empty, what is published, recent syncs.
- **"A team is missing"** diagnostic. Reads live, writes nothing, and lists every
  team the source offered with why it is not published and what to do about it.
  Teams in completed programs are excluded, because the first version buried the
  one real answer under several hundred historical rows.
- Metro lookup, so a suburb publishes as the metro people recognise. More useful
  to a reader and less identifying, since the city on a registration is the
  registrant's home address rather than the team's.
- Name casing for people, not for teams.
- Payment gating, via a third field category that is read and never stored.
- Post-write verification: a field that is hashed but not persisted is now a
  refusal instead of a sync that repeats the same changes for ever.

## 0.1.0

First release. CLI only.
