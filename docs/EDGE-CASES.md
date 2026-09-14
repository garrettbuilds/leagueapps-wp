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

## Found on production, September 14, 2026

Every one of these came from running against a live tournament. None would have
appeared in a unit test.

### A field in the hash but not in the table never converges

`location` was added to `Team`, to `visible_hash()` and to the renderer, and not
to the database column list. Nothing errored. The row was written, the value was
dropped, and because the hash still said the data differed, **every subsequent
run planned the same eleven updates again** - for ever, silently, with a daily
cron.

`SyncApplier` now reads the rows back after writing and compares hashes. A write
that does not persist is `WRITE_DID_NOT_PERSIST` rather than a success. Proven by
syncing twice: eleven updates, then `NO_CHANGE`.

### The credential cannot live in /opt on a hardened host

GridPane sets an ACL denying its `GridPane-System-Users` group on `/opt`, and the
site user is in that group. A `.p12` there is unreadable whatever its own mode
says. Above `htdocs` but inside the site's own tree is private and reachable:
`/var/www/<site>/private/`, mode 0600.

### A read-only guard blocks the token request

A site may carry a `pre_http_request` filter refusing every non-GET to the vendor.
That is worth having, and it also blocks OAuth, because the token call is a POST
even though it only buys read access. Scope an exception to exactly one host and
one path rather than relaxing the guard.

### WP-CLI and the web can run different PHP

Every command-line check passed while every browser request returned 500. The web
pool was PHP 8.0 and WP-CLI was 8.2. Check the pool, not the CLI.

### The host theme wins the CSS fight

Jump pills are a link that is the only child of a list item, which is exactly what
content themes target:

```
.entry-content li > a:only-child { padding: 2px 0 }   (0,2,2)
.lawp-teams .lawp-teams__jump-link { padding: .35em .75em }   (0,2,0)
```

The block's own layout lost. Three classes clears it. Measure the computed value
on a real theme; guessing cost two attempts.

### Adopt the theme's table class, do not invent one

A bespoke class on the `<table>` inherited nothing, because themes style
`.wp-block-table > table` and `.wp-block-table thead th` - the WRAPPER carries the
class. Putting `wp-block-table` on the scroll container made the block pick up the
site's existing treatment: purple header rule, sand alternating rows, on any theme.

### Payment status is read, never stored

A league asked that only teams which have paid appear. That means reading
`paymentStatus`, which is denied for display, so a third category exists:
`DECIDE_ONLY`. `Team` has no property that can hold it. Only `paymentStatus` -
`amountPaid`, `invoiceId`, `outstandingBalance` and `lastPaymentDate` answer "how
much" and "when", which no visibility decision needs.

An ABSENT status is not settled. A source that stopped sending the field would
otherwise publish everybody.

### Registration status is not proof of payment

On the day this was checked, every `SPOT_RESERVED` team was also `PAID` and the
one `UNPAID` team was `SPOT_PENDING`. That is correlation. A spot can be reserved
before a payment clears, so the payment gate is explicit.

### The city is the registrant's, not the team's

`city` sits between `address1` and `zipCode`. Swamp Donkeys showed *Frisco*;
Dallas Vengeance showed *Fort Worth*. Publishing it publishes part of somebody's
home address.

Mapping to a metro fixes both halves: `Dallas` is more use to a reader than
`Frisco`, and it narrows a named person to eight million people rather than two
hundred thousand. Showing a location INSTEAD of a manager's name, rather than
beside it, breaks the link between a person and where they live.

Two of thirteen teams had no city at all. State casing arrived as `TX`, `Tx` and
`mN` on one tournament.

### Names arrive shouted

`Mathew HALL` is a form-filling artefact, not a choice. Tidied for people, left
alone for teams: normalising `ATX Dillos` or `STL Arch Nemesis` would produce
`Atx` and `Stl`. A word that is not entirely uppercase is never touched, so
`McDonald` survives.

The first attempt skipped words of three letters or fewer to protect `JR` and
`II`, which also skipped `IAN` and produced `IAN Smith`. A named keep-list says
what it protects instead of hoping length correlates with it.

### Custom registration questions arrive keyed by their text

```
What is your current NAGAAA City?
What position(s) do you play?
```

Useful, and fragile: the key changes if somebody edits the wording. On this site
they were filled on 6 of 871 rows, all from an older program's form. Treat them as
opt-in per field, configured by the operator, never assumed.

### LeagueApps `location` is the venue

Top-level `location` is `Krieg Softball Complex`, not a person or a team. Worth
knowing before wiring anything to that name.

### A preset is a starting point

The letter-grades preset ships standalone A and B. This tournament runs a combined
A/B, so both sat on the page saying "no teams registered yet" about divisions that
do not exist.
