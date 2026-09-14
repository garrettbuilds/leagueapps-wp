# Edge cases

Every one of these came out of real data from two live Sites, not imagination.

## Division detection

| Case | Real example | Handling |
|---|---|---|
| Substring collision | `2022 Open End of Season Tournament` contains `open e` | Word-boundary match. Was a live bug |
| Renamed Division | `Masters` became `Legends` | Both map to Legends |
| Singular and plural | `Open Master Division` and `Open Masters Division` | Both aliased |
| Apostrophe variants | `Women's`, `Women’s`, `Womens`, `Women` | Curly quotes normalised, all aliased |
| Near-duplicate Divisions | `Legends` and `Legends D` | Collapsed to one. Split only if a Site runs both |
| Word order changed mid-decade | `Spring 2017 Open D` vs `2019 Spring Open D` | Position-independent matching |
| Historic Divisions | `Open D1`, `Open D2`, `Masters` | Held, not guessed |
| Field set but unknown | `Pool Play Group 3` | **Held.** Never falls back to the Program name |
| Non-competition Programs | `Free Agents`, `Ratings Clinics`, `FB Test` | Skipped |

## Teams

| Case | Real example | Handling |
|---|---|---|
| Same Team, different casing | `RIVERSIDE RANGERS` / `Riverside Rangers` | Group on `teamId`, never the name |
| Same Team name, two Divisions | `RIVERSIDE RANGERS` in D and E | Genuinely two Teams. Keyed by Division plus `teamId` |
| Same Team name, different `teamId` | `Harbour Hounds` and `Harbour Hounds D` | Two Teams, shown separately |
| One Registration per Team | Tournament where only Captains register | `min_roster = 1`, and hide the player count |
| Stray single Registration | A Captain who registered before the roster filled | `min_roster = 4` for leagues |
| One person Captains two Teams | Confirmed in live data | Fine. Shown on both |
| Team with no Captain | Several, live | Column left blank, not "Unknown" |
| Registration not holding a spot | `SPOT_PENDING`, `WAITING_LIST` | Excluded. Not yet in the tournament |
| Empty Division | A/B, Women's, Legends with no Registrations yet | Section omitted, not rendered empty |

## Privacy

| Case | Handling |
|---|---|
| Key grants more scope than needed | `members-2` absent from the endpoint map. No code path reaches it |
| Registration rows carry contact details | Allowlist at the parse boundary. 9 of 21 fields kept in testing |
| Somebody widens the allowlist | Denylist wins in `reduce()`, and `contains_denied()` fails the run loudly |
| Names on a public page | Permitted. Captain and coach credits are ordinary public information |
| Dates of birth, addresses, phone, payment | **Never.** No configuration turns them on |

**The honest caveat:** Team names exist only on Registration records, so the HTTP response
contains personal data in transit for the moment between the API returning it and the allowlist
dropping it. It is never stored, logged or displayed. "We never read it" would be inaccurate.

## API

| Case | Handling |
|---|---|
| Discovery document advertises a flow that 401s | Use the `jwt-bearer` grant. See docs/LEAGUEAPPS-API.md |
| Token expires mid-run | 401 discards the cached token; caller retries once |
| Cursor does not advance | Pagination stops rather than looping forever |
| Rate limited | 429 reported with `Retry-After`. No documented limit exists yet |
| Endpoint deprecated | `members`/`registrations` now 410. Use the `-2` names |
| Only one parameter sent | 400 naming the other. Both are always required |
| Key cannot read another Site | `insufficient_scope`. One key per Site, confirmed |

## Things that do not exist

Probed across two Sites with two keys. All 404:

```
schedule  schedules  games  standings  results  scores
teams     divisions  brackets  pools  events  attendance
```

**There is no Teams endpoint.** Teams are derived from Registrations. **There are no Schedules
or Standings at all** in the private API, so a schedule page needs an embed until LeagueApps
exposes one.
