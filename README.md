# LeagueApps for WordPress

Publishes your LeagueApps **Teams** and **Divisions** on your own site, instead of sending
visitors away to a LeagueApps page.

Read-only, one-way, and it never touches member contact details.

**Status:** 0.1.0. The API client, Division detection and Team grouping are built and tested
against two live Sites. The display block and scheduled sync are next.

---

## What it does

LeagueApps runs your registrations. Your website is where people look. Nothing joins them, so
"who is registered in each Division" is usually six outbound links or a table somebody updates
by hand.

This reads your Site through the LeagueApps API and gives you Teams grouped by Division, on
your own page, in your own design.

## What it deliberately does not do

| | Why |
|---|---|
| Write anything to LeagueApps | One-way. The token's scope is read-only at their end too |
| Read your member list | `members-2` is absent from the endpoint map. No code path reaches it |
| Store contact details, dates of birth, addresses or payment data | Not needed to show a Team list. Blocked by an allowlist *and* a denylist |
| Guess a Division it does not recognise | Holds the Team for review instead. Wrong is worse than absent |
| Run on a page load | WP-CLI only, so a visitor never waits on an API |

Captain and coach **names** are shown, because a team listing credit is ordinary public
information. Nothing else about a person is.

## Quick start

```bash
# wp-config.php
define( 'LAWP_CLIENT_ID', '...' );                     # the Private API Key NAME
define( 'LAWP_CERT_PATH', '/opt/.../leagueapps.p12' );  # outside the web root, 0600
define( 'LAWP_SITE_ID',   1234 );

wp leagueapps health      # credentials and connectivity
wp leagueapps discover    # how does this Site organise its Divisions?
wp leagueapps teams --program="2026 Summer Classic"
```

`discover` is worth running first. LeagueApps supports two Division models and this reports
which one your Site uses before you configure anything.

## Documentation

| | |
|---|---|
| [docs/DIVISIONS.md](docs/DIVISIONS.md) | Both Division models, and how detection works |
| [docs/EDGE-CASES.md](docs/EDGE-CASES.md) | Every real-world case found in live data |
| [docs/LEAGUEAPPS-API.md](docs/LEAGUEAPPS-API.md) | The API as it behaves, which is not what the docs say |

## Before you file a bug about authentication

Three things cost us a day, and none are in LeagueApps' documentation:

- **Their OAuth discovery document describes a flow that returns 401.** Use the `jwt-bearer`
  grant, not `private_key_jwt`.
- **The data is not on the host you authenticate against.** Exports live on `admin.leagueapps.io`.
- **Your client ID is the Private API Key's *name***, and the `.p12` password is `notasecret`.

Full details in [docs/LEAGUEAPPS-API.md](docs/LEAGUEAPPS-API.md).

## What is not available

Probed across two Sites with two keys: **there is no Schedules or Standings endpoint.** Teams
are derived from Registrations because there is no Teams endpoint either. If you need a
schedule on your site today, embed the LeagueApps view.

## Contributing

The tests that matter are in Division detection and the field allowlist. If you add a Division,
add its aliases and a case proving a shorter alias does not swallow it — `Legends D` matching
`d` is the bug class to watch for.

**Never widen the denylist in `class-lawp-fields.php` without a discussion.** It is what stops
somebody's date of birth reaching a public page.

## License

GPL-2.0-or-later.
