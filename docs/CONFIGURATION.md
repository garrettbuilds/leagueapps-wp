# Configuration

Two questions, answered in two different places. Keeping them apart is the whole
design.

```
The admin wizard answers:
  Which LeagueApps event and divisions are we syncing,
  how often, and under what safety rules?

The page editor answers:
  Where should this approved data appear,
  and how should visitors see it?
```

A page editor adjusting a heading must not be able to change the selected
program, the division rules, or the sync schedule.

## Where each setting lives

| Setting | Where | Why |
|---|---|---|
| Credentials | `wp-config.php` only | Never in the database, never in the UI |
| Site id | Plugin setup | One source connection |
| Program or event selection | Event wizard | Source-of-truth data selection |
| Division mapping, labels, order | Event wizard | Must be identical on every page |
| Unknown-division policy | Event wizard | A data-integrity rule, not a preference |
| Sync frequency | Event wizard | Operational behaviour |
| Circuit-breaker thresholds | Event wizard | Safety rule |
| Cache generation, last sync | Plugin-managed | Never editor-controlled |
| Whether teams appear on a page | Block insertion | Editorial |
| Show captain, roster count, jump links | Block sidebar | Presentation |
| Heading text and level | Block sidebar | Page hierarchy |
| Display-name overrides | Restricted admin | Data governance, not page design |

## No page is ever taken over

The wizard discovers what a Site contains and shows it. It does not decide that a
program belongs on a URL, and it never rewrites an existing page.

After configuration it offers four choices, and waits:

```
○ Add a Teams block to a page I choose
○ Create a Teams page as a DRAFT
○ Create a hub page and supporting pages as DRAFTS
○ Configure the data only; I will add blocks later
```

Anything it creates is a draft. The operator reviews it, places it in navigation
and publishes it, the same as any other page.

## Blocks, not page binding

The page stores a small block configuration and no LeagueApps data:

```json
{
  "eventKey": "summer-classic-2026",
  "resource": "teams",
  "showJumpLinks": true,
  "showCaptain": false,
  "showLastUpdated": true,
  "showSourceLink": true
}
```

The front end always reads the current validated local cache. It never calls
LeagueApps from a visitor's browser, and there is no public URL parameter that
forces a refresh — that would be a way for one visitor to generate unlimited API
load.

## Only offer controls for data that exists

Discovery records what a Site actually returns, and the block editor shows
controls only for confirmed capabilities:

```php
[
    'teams'          => true,
    'team_captain'   => true,
    'roster_count'   => true,
    'schedule'       => false,
    'standings'      => false,
    'locations'      => false,
    'public_rosters' => false,
]
```

`schedule` and `standings` are false everywhere, and not because they are
unfinished. **These endpoints do not exist.** See `LEAGUEAPPS-API.md`. A
standings control that renders nothing is worse than no control, because it makes
an operator think they have configured something wrong.

When a field is present but patchy, say so rather than hiding it:

```
Captain names were found for 76 of 84 teams.
If enabled, teams without one show a blank cell.
```

## One page or several

A hub page with focused subpages, not one page holding everything.

```
/event/            overview, dates, registration, travel
/event/teams/      registered teams by division
/event/schedule/   when there is a schedule source
```

Separate pages mean a team change purges `/event/teams/` and nothing else. One
combined page means every change purges everything, and a long schedule makes the
page unusable on a phone.

A compact summary block on the hub page covers the at-a-glance case without
duplicating the dataset:

```
84 teams across 7 divisions are registered.
[View all teams]
```

## Division mapping is the step that cannot be skipped

Every source value with teams behind it must be resolved, hidden, or explicitly
held before an event can activate:

```
Source value       Teams   Public heading       Visible
A                     12   A Division           Yes
B                     12   B Division           Yes
Women's                8   Women's Division     Yes
Legends D             12   Legends Division     Yes
Competitive Open       2   -                    HOLD FOR REVIEW
```

`presets/` holds starting points — letter grades, age groups, skill tiers — but
nothing loads one unless an operator picks it, and the wizard still shows every
value the Site returned beside it.

## Unknown-division policy

| Policy | Use when |
|---|---|
| `hold_for_review` | Default. During registration, when a mapping is probably just missing |
| `show_pending` | You want a visible "Division Pending" section |
| `hide` | Internal or test divisions you never publish |
| `fail_sync` | Close to the event, when a wrong public list costs more than a stale one |

`hold_for_review` early, `fail_sync` once the list is operationally important.

## Sync scheduling

One server cron entry. The plugin decides what is due.

```cron
*/5 * * * * flock -n /var/lock/leagueapps-sync.lock \
  /usr/local/bin/wp --path=/var/www/example.com/htdocs \
  leagueapps run-due-syncs --quiet \
  >> /var/log/leagueapps-sync.log 2>&1
```

Two locks, because they catch different things. `flock` stops two cron processes
on one server. The database lock stops a manual sync started while cron is
running, a second web node, and a job that died holding a lock.

Not a transient: a persistent object cache evicts under memory pressure, and an
evicted lock is an absent lock.

Do not put the schedule in crontab. Changing "every 6 hours" to "every 30 minutes"
for a tournament weekend should not require a volunteer to edit server
configuration.

## What the plugin needs from a host

| Requirement | Why |
|---|---|
| Server cron, or a host cron panel | WP-Cron fires on page loads, which is not a schedule |
| WP-CLI as the site user | Sync runs outside PHP-FPM and its memory limit |
| A readable credential path | **The cron user, which is usually not the PHP-FPM user** |
| Outbound HTTPS | API reads |
| An email or monitoring path | Somebody has to hear about three failures in a row |

No Redis, no queue service, no external scheduler. Add those when traffic or a
multi-server deployment makes the locked WP-CLI model insufficient, not before.
