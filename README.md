# LeagueApps for WordPress

Publish your LeagueApps Teams and Divisions on your own site. Read-only, one-way,
and it never touches member contact details.

Built for one LGBTQ+ softball league and a tournament they host, then generalised
so it does not assume their vocabulary, their division model, or their league
structure. If something in here still does, that is a bug — please open an issue.

## What it does

- Reads registrations from your LeagueApps Site and derives a team list from them
- Groups teams by division, handling **both** LeagueApps division models
- Caches the result in your own database, so visitors never wait on an API call
- Renders through a Gutenberg block, or a shortcode for any other builder
- Refuses to publish anything it cannot verify

## What it deliberately does not do

- **Write to LeagueApps.** There is no write method in the client, and adding one
  would mean adding it visibly to an interface rather than passing a different
  string to a general function.
- **Read member data.** The member endpoint is absent from the endpoint map, so
  no code path reaches it.
- **Publish contact details.** Registrations carry email, phone, address, date of
  birth, gender and payment status. All of it is dropped at the parse boundary.
  No setting turns any of it on.
- **Show schedules or standings.** Those endpoints do not exist. See
  [docs/LEAGUEAPPS-API.md](docs/LEAGUEAPPS-API.md).
- **Take over a page.** It creates drafts and offers blocks. It does not rewrite
  anything you already published.
- **Delete your data** when you deactivate it, or when you delete it, unless you
  switched that on first. See [docs/DATA.md](docs/DATA.md).

## Requirements

- WordPress 6.4+, PHP 8.1+
- WP-CLI, and server cron or a host cron panel
- A LeagueApps Private API Key and its `.p12` certificate

A public API key will not work. That is not an oversight — see
[docs/LEAGUEAPPS-API.md](docs/LEAGUEAPPS-API.md).

## Install

```php
// wp-config.php
define( 'LAWP_CLIENT_ID', 'your-private-api-key-name' );
define( 'LAWP_CERT_PATH', '/opt/credentials/leagueapps.p12' );
```

The `.p12` belongs outside the web root, mode 0600, owned by the user your cron
job runs as — which is usually **not** the user PHP-FPM runs as.

```bash
wp plugin activate leagueapps-wp
wp leagueapps health
wp leagueapps discover --site=1234
```

Discovery tells you how your Site is organised before you configure anything. Run
it first. It is the difference between mapping your divisions and guessing them.

## The admin screen

**LeagueApps** in the sidebar. Written for whoever inherits the site, not for
whoever installed it.

It leads with whether the connection works, then for each event: how many teams
are published, which divisions are configured but empty, what is on the public
page, and when it last synced.

Three buttons:

| | |
|---|---|
| **Check for changes** | Reads LeagueApps and reports exactly what would change. Writes nothing. |
| **Update the page now** | Applies it. |
| **A team is missing** | The one that earns its place. |

### "A team is missing"

The question every administrator asks, and the one a plugin usually cannot
answer: *it is in LeagueApps, why is it not on the page?*

It reads live, changes nothing, and lists every team the source offered with what
happened to it and what to do:

```
Dallas Vengeance   Registration status is SPOT_PENDING, not Spot Reserved
                   The registration is unfinished in LeagueApps. Confirm the spot there.
```

Each row says what to DO. A reason code alone sends somebody back to whoever
installed the plugin, which is the situation this screen exists to end.

Teams in finished programs are left out. The first version reported every team the
Site had ever had - several hundred rows from completed 2018 seasons - and the one
team genuinely missing was lost among them.

## Sync

Dry run is the default and writes nothing:

```bash
wp leagueapps sync --event=summer-classic-2026
```

Applying takes two deliberate flags:

```bash
wp leagueapps sync --event=summer-classic-2026 \
  --mode=apply --confirm=apply-teams-summer-classic-2026
```

One cron entry; the plugin decides what is due:

```cron
*/5 * * * * flock -n /var/lock/leagueapps-sync.lock \
  /usr/local/bin/wp --path=/var/www/example.com/htdocs \
  leagueapps run-due-syncs --quiet >> /var/log/leagueapps-sync.log 2>&1
```

## Safety rules that will stop a sync

These exist because each one describes a way a public page can become wrong.

| Rule | What it prevents |
|---|---|
| A read must finish | A timeout emptying the page, because absent rows look like withdrawn teams |
| Mass-removal circuit breaker | The wrong program silently replacing 84 teams with 8 |
| Unknown divisions are held | A team published in a division nobody assigned it to |
| Source identity check | Last season's ids quietly syncing last season's teams |
| Denylist assertion | A private field ever reaching the database |
| Plan staleness | Applying a plan reviewed before lunch |

## Docs

| | |
|---|---|
| [BLOCK.md](docs/BLOCK.md) | The Teams block and shortcode, and what the editor offers |
| [DIVISIONS.md](docs/DIVISIONS.md) | Both division models, and why the map is configuration |
| [DRY-RUN.md](docs/DRY-RUN.md) | What a dry run proves, and the exit codes |
| [CONFIGURATION.md](docs/CONFIGURATION.md) | Wizard, blocks, and which setting lives where |
| [DATA.md](docs/DATA.md) | What is stored, caching, deactivation and uninstall |
| [TESTING.md](docs/TESTING.md) | The suite, and the two dead guards mutation testing found |
| [EDGE-CASES.md](docs/EDGE-CASES.md) | Real cases found in live data |
| [LEAGUEAPPS-API.md](docs/LEAGUEAPPS-API.md) | Auth, endpoints, and where the docs are wrong |

## Tests

```bash
composer install && composer test
```

184 tests, no WordPress, no database, no network.

## Licence

GPL-2.0-or-later.
