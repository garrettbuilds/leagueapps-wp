# Changelog

## [0.1.0] - 2026-09-14

### Added
- OAuth client for the LeagueApps API, using the grant that actually works.
- GET-only reader for Programs and Registrations, with keyset pagination.
- Division detection supporting **both** LeagueApps models: the `division` field when set, the
  Program name when not. Verified against 227 Program names across two live Sites.
- Team grouping from Registrations, keyed on `teamId` so casing variants do not split a Team.
- Field allowlist and denylist. Names permitted; contact details, dates of birth, addresses and
  payment data blocked at the parse boundary and undetectable downstream.
- `wp leagueapps discover`, which reports how a Site organises its Divisions.
- `wp leagueapps health` and `wp leagueapps teams`.

### Known limits
- No Schedules or Standings. Those endpoints do not exist in this API.
- No display block yet. CLI only.
- One key per Site: a key cannot read another Site.
