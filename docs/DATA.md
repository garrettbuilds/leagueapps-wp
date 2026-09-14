# Your Data

## Deactivating deletes nothing

There is no deactivation hook in this plugin, on purpose.

Deactivating a plugin is how people test a theory about an unrelated bug. It is
not consent to delete anything, and a plugin that treats it as consent is a
plugin nobody can safely troubleshoot.

## Deleting deletes nothing either, unless you asked first

`uninstall.php` checks one setting:

```
Settings -> LeagueApps -> Delete all data when this plugin is deleted
Default: OFF
```

Off by default because the usual reasons to delete a plugin are reinstalling,
moving hosts, or tidying a plugin list. In all three, leaving the tables alone
means a reinstall picks up where it left off. A stale table costs a few kilobytes.
A deleted one costs an afternoon of remapping divisions.

Turn it on *before* deleting if you want a clean removal. Turning it on after the
plugin is gone does nothing, because the setting is gone too.

### What "delete all data" removes

```
wp_lawp_teams        the cached public team list
wp_lawp_sync_runs    run history: counts, codes, hashes
wp_lawp_locks        sync locks
lawp_settings        plugin settings
lawp_events          event configuration and division mappings
lawp_data_generation cache generation counters
lawp_schema_version
```

It does not touch pages, posts or blocks. A page with a Teams block on it stays
exactly as it is and renders an empty state.

It cannot touch LeagueApps. There is no write method in the client.

## What is stored, and what is not

Stored, per team:

```
LeagueApps team id, program id, program name,
team name, an optional display-name override,
division key and label, the raw source division value,
roster count, captain name if the event enables it,
a hash of what a visitor sees, active flag, timestamps
```

Never stored, never rendered, never logged:

```
email, phone, mobilePhone
address1, address2, city, state, zipCode, country
birthDate, gender
paymentStatus, amountPaid, totalAmountDue, outstandingBalance
invoiceId, lastPaymentDate, waiverAcceptedTimestamp
userId, userProfileId, photo
```

Two lists guard this, not one. `ALLOW` is what gets taken; `DENY` is what must
never be taken even if somebody widens `ALLOW` by mistake. In an open-source
plugin that will receive patches, the second list is the one that matters. If they
ever overlap through an edit, `DENY` wins, and `contains_denied()` fails the run
loudly.

**One honest limitation.** Team names exist only on registration rows, so a
response carries personal data in transit for the moment before the allowlist
drops it. It is never stored and never logged. "We never read it" would be
inaccurate.

Captain names are published when an event enables it, because a team credit is
ordinary public information on a team listing. Contact details and dates of birth
are not, and no setting turns them on.

## Run history carries no names

A row in `wp_lawp_sync_runs` holds counts, status codes, a source hash and a
plugin version. Not team names, not rows, not responses. It is an audit trail, and
an audit trail that accumulates a copy of the data defeats the point of limiting
what is stored.

## Caching, and the one risk it creates

The database may be current while visitors still see an older page. The fix is not
to avoid caching; it is to make every layer safe to lose.

```
Custom tables          durable, authoritative
Object cache / Redis   disposable acceleration
Page and CDN cache     disposable distribution
```

Only the custom tables are a source of truth. Everything else must be safe to lose
and cheap to rebuild — so cache reads use strict `false ===` comparison, and a
cache miss queries the table rather than rendering "no teams".

### Never cache a failure as content

An empty response that gets cached as "no teams registered" propagates to the page
cache and then to the CDN. A failed read preserves the last validated list and
records the failure separately.

### Generation numbers, not just deletes

Every view cache key carries an event generation:

```
lawp:v1:event:summer-classic-2026:teams:g43
```

A successful sync that changes something visible bumps 43 to 44. The old key
becomes unreachable rather than needing to be found and deleted, which matters
because a delete can fail silently on one node of a multi-server install.

### Purge order

Data first, cache second, and only when something changed:

```
1-10  validate, plan, check safety rules, write in a transaction
11    commit
12    bump the generation
13    purge only the page this event feeds
```

If step 13 fails after the commit, the data is still correct: record
`CACHE_PURGE_FAILED` and retry the purge alone. Do not re-sync — a failed CDN
purge is not a reason to call the API again.

If anything in 1 to 10 fails: no purge, no generation bump, no writes, and the
page keeps serving its last validated list.

Never a site-wide flush. Purging everything after each sync turns a six-hourly
no-op into a six-hourly cold cache for every page on the site, and hides whether
the targeted purge works at all.
