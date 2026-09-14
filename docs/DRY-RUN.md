# The Dry Run

A dry run is not a connectivity check. It answers three questions:

1. Can this response be trusted?
2. Does every team land in a division somebody approved?
3. What exactly would change on the public page?

If it only proved the API returned HTTP 200, it would prove nothing. The league
subdomain serves an HTML page with a 200 for any path, including nonsensical
ones.

## The contract

```
dry run:
  MAY  read LeagueApps.
  MAY  read the existing cache and configuration.
  MAY  calculate normalisation, hashes, diffs and validation results.
  MUST NOT write the team cache.
  MUST NOT create, update or delete any post or page.
  MUST NOT change settings, division mappings, overrides or cron.
  MUST NOT call a LeagueApps write endpoint. There is no method to call one.
  MUST NOT print credentials or raw rows.
```

Dry run is the default. Applying takes two deliberate flags:

```bash
# Plans and reports. Writes nothing.
wp leagueapps sync --event=summer-classic-2026

# Writes.
wp leagueapps sync --event=summer-classic-2026 \
  --mode=apply --confirm=apply-teams-summer-classic-2026
```

## One pipeline, not two

There is no separate preview implementation. `SyncPlanner::plan()` returns an
immutable `SyncPlan`; `SyncApplier::apply()` consumes one. A dry run is the
planner plus a report. A live run is the planner plus the applier.

```php
$plan = $planner->plan( $config, $source, $existing );

if ( 'apply' !== $mode ) {
    return $reporter->text( $plan, $mode );
}

$applier->apply( $plan );
```

Separate dry-run logic drifts, and the day it drifts is the day the preview stops
describing the thing that runs.

The planner is also handed a `ReadOnlyTeamRepository` in dry-run mode, which
throws on every write method. It has no write path anyway. The wrapper is there
so that if one is ever added by accident, a test fails with a stack trace instead
of a public page quietly changing.

## Completeness, and why there is no "4 of 4 pages"

Most dry-run designs assert that every page of a paginated response arrived, by
comparing against a total the API reports. **These endpoints report no total.**
They are keyset-paginated: you pass the highest row id you have seen and get the
next thousand rows. There is nothing to compare against.

So completeness is proved by *how the read ended*, not by a count:

| Ended because | Complete? | Meaning |
|---|---|---|
| `exhausted` | Yes | A short or empty page. The only ending that means "no more". |
| `timeout` | No | We do not know what we did not read. |
| `rate_limited` | No | Stopped early with a `Retry-After`. |
| `http_error` | No | 401, 404, 500. |
| `cursor_stalled` | No | The cursor stopped advancing. Stopped rather than loop forever. |
| `page_cap` | No | Hit the page limit. There is more. |
| `not_json` | No | HTML came back with a 200. |

Anything but `exhausted` produces `INCOMPLETE_SOURCE`, status `FAILED`, and **no
deactivation is planned at all**. This is the most important rule in the plugin.
An incomplete read looks exactly like teams withdrawing: rows that were there
last time are absent now. Confusing the two empties a public page because a
request timed out.

## Two different refusals

These are not the same, and the reports differ:

**An unreadable source plans nothing.** There is nothing to review. The rows
might be perfectly fine and simply not have arrived.

**A source that reads fine but trips a safety rule plans everything, and applies
none of it.** A circuit breaker report *should* list all 76 removals it is
refusing to make, because that list is what tells an operator whether it picked
the wrong program.

That distinction cost a failing test to get right. The first version of the
invariant asserted no unsafe plan contained a deactivation, which is wrong: it
would have hidden exactly the detail an operator needs.

## Outcomes and exit codes

| Result | Meaning | Exit | Unattended apply? |
|---|---|---:|---|
| `PASS` | Valid, no warnings | 0 | Yes |
| `NO_CHANGE` | Cache already matches | 0 | Nothing to do |
| `WARNING` | Valid, needs a person | 2 | No |
| `BLOCKED` | Safety rule, config, or circuit breaker | 3 | No |
| `FAILED` | Could not read the source completely | 1 | No |

A warning blocks an unattended apply because a held team is a question, and a
cron job cannot answer it. A person can, with `--allow-warnings`.

Collapsing warnings into "success" is how a scheduled job applies a plan that
needed somebody to look at it.

## Circuit breaker

```
Cached teams:   84
Source teams:    8
Would remove:   76 (90%)
Limit:          10 teams or 20%
```

A source reporting 8 where the cache holds 84 is far more likely to be the wrong
program, a changed key scope, or a read that ended early in a way we failed to
detect, than 76 teams withdrawing at once.

Both limits apply: a **count**, so a small league is protected, and a
**percentage**, so a large one is. Two teams withdrawing from 84 is normal and
applies without comment.

## Approved applies

An approved plan is a claim about the source at one moment. Applying it later
would write a division assignment nobody reviewed, so:

- `PLAN_SUPERSEDED` if the source hash no longer matches.
- `PLAN_STALE` after 30 minutes.

Thirty minutes because an operator who reviews a plan, goes to lunch and comes
back to press Apply should get a fresh plan, not the one from before lunch.
