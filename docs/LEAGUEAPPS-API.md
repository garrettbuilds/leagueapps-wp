# The LeagueApps API, as it actually behaves

Verified against a live site on 2026-09-14. Most of this contradicts or is absent from the
official documentation, and every line was established by testing.

## Auth

```
token endpoint   https://auth.leagueapps.io/v2/auth/token
grant_type       urn:ietf:params:oauth:grant-type:jwt-bearer
assertion        RS256 JWT — iss = sub = client_id, aud = the token endpoint
client_id        the Private API Key NAME from the console
.p12 password    notasecret
token            899 seconds, scope leagueapps:export
```

### Three traps

**The discovery document describes a flow that does not work.**
`/.well-known/openid-configuration` advertises `authorization_code` and `private_key_jwt`.
Following it — JWT as `client_assertion`, `grant_type=client_credentials` — returns
**401 invalid_client**. The older `jwt-bearer` grant is what the service accepts.

**`auth.leagueapps.io/v2/auth` is not the token endpoint.** It 302s to a human login page.
Only the discovery document names `/v2/auth/token`.

**Data is not on `api.leagueapps.io`.** That host serves site metadata and 404s for everything
else. Exports live on **`admin.leagueapps.io`**, discoverable only from a 410 on the old path
whose `dev-message` names the replacement.

### The scope is a real guarantee

`leagueapps:export` is read-only and enforced by LeagueApps. The credential itself cannot
write, which is stronger than anything we enforce on our side.

## Endpoints

`https://admin.leagueapps.io/v2/sites/{siteId}/export/`

| Path | Returns |
|---|---|
| `members-2` | People |
| `registrations-2` | Participation |
| `programs` | Seasons |

All three require **both** `last-updated` and `last-id`. Passing one returns a 400 naming the
other.

- `last-updated` — milliseconds since epoch. The incremental watermark.
- `last-id` — keyset cursor. `0` first, then the highest `id` from the previous page.
- Page size is **1,000**, measured.

So a full backfill is 4 calls for members and 11 for registrations. A nightly delta is usually 1.

No rate-limit headers are returned. Until LeagueApps confirms a number: one request at a time,
sequential pagination.

## Vocabulary

None of this is guessable, and all of it differs from what a reasonable person would assume.

| | Values |
|---|---|
| Roles | `PLAYER`, `CAPTAIN`, `FREEAGENT`, `Coach`, `Volunteer`, `Non Playing Member` |
| Registration status | `SPOT_RESERVED`, `SPOT_PENDING`, `WAITING_LIST` |
| Payment status | `PAID`, `NA_TEAM_PAYS`, `UNPAID`, `NA_FREE`, `REFUND`, `VOID`, `PARTIAL` |

**`CAPTAIN` is what most people would call a manager.** An importer looking for `manager`
matches nothing and reports success.

**Status and payment are separate questions.** A registration can be `SPOT_RESERVED` and
`UNPAID`, or `SPOT_PENDING` and `PAID`. Participation means `SPOT_RESERVED` **and** paid,
team-paid, or comped.

## What the API does not have

**Email deliverability status.** `members-2` has no equivalent of the CSV's `Email Status`,
where bounces, spam complaints and unsubscribes live. `newsletterOptIn` is a different fact:
what somebody chose, not what happened to their address afterwards.

A sync built on the API alone will mail people whose addresses are dead. **Both sources are
needed**: the API nightly for participation, a Members CSV occasionally for deliverability.

## Open questions

Asked of LeagueApps support, unanswered at time of writing:

1. What does `SPOT_PENDING` + `PAID` mean? It is a large share of registrations and changes
   how many seasons people are credited with.
2. Does `last-updated` on `members-2` move when somebody registers, or only when their profile
   changes?
3. Are deleted records returned with `deleted: true`, or do they simply vanish? A deletion that
   vanishes is invisible to an incremental sync.
4. What are the rate limits?
5. Is `jwt-bearer` being deprecated in favour of the flow the discovery document advertises?
6. Can one key read a second site, or does each need its own?

## The "public API key" does not open these endpoints

Third-party guides — and some LeagueApps material — describe a public API key that
reads public program and team data. Tested against two Sites with a freshly
generated public key, that path does not authenticate the export endpoints. Every
variation returned the same `Invalid API Key` response, identically for a real
key, a fabricated key, and no key at all.

An error that does not change when the input changes is not telling you the key is
wrong. It is telling you the parameter is not being read.

**What works is OAuth 2.0 with an RS256 JWT bearer grant**, signed with the `.p12`
certificate issued alongside a Private API Key. That is the only authentication in
this plugin.

Two consequences worth stating plainly:

- Public team data still requires a private credential. Plan for a server-side
  credential path, not a key in a settings field.
- Any design premised on browser-side or keyless reads does not apply here.

## There is no schedules or standings endpoint

Probed across two Sites with two keys: `schedule`, `schedules`, `games`,
`standings`, `results`, `scores`, `teams`, `divisions`, `brackets`, `pools` and
`events` all 404.

Teams are derived from registrations, because every registration carries the team
it belongs to. Schedules and standings are not available at all.

This is why the block editor shows no schedule or standings controls. They are not
unfinished features — the data is not there to read.

## A 200 is not evidence of an endpoint

The league subdomain serves an HTML page with a 200 for any path, including
nonsensical ones. Probing for endpoints by status code will produce a list of
endpoints that do not exist.

Check the content type and require a JSON array of rows before treating a response
as a read. `not_json` is a distinct termination reason in this plugin for exactly
this.

## The guard that has now cost twice

The host allowlist matched `leagueapps.com`. The API is on `leagueapps.io`.

Same class of mistake twice in this project: a check written against the domain
somebody had in front of them rather than the one the code actually calls.

## The cursor is `last-updated`, not `last-id`

Both parameters are required, and passing one without the other returns a 400
naming the missing one. That makes them look like a pair of equals. They are not.

```
last-updated   millisecond epoch watermark   THIS is what advances
last-id        tie-breaker for rows sharing that timestamp
```

Advancing only `last-id` returns the same first page for ever. On a Site with
fewer than a thousand rows everything arrives in one page and nothing shows. On a
Site with ten years of registrations it caps silently at one thousand.

That is not hypothetical. On the league Site here, 8,000+ rows were reachable and
1,000 were being read, and the only reason it failed loudly rather than publishing
a tenth of a league was a guard that refuses to loop when the cursor stops moving.

Correct paging:

```
page 1: last-updated=0              -> 1000 rows, max lastUpdated 1413935079000
page 2: last-updated=1413935079000  -> 1000 rows, max lastUpdated 1441900633000
page 3: last-updated=1441900633000  -> 1000 rows, ...
```

Deduplicate by `id`. A row sharing the boundary timestamp can legitimately appear
on both sides of it.

Verified end to end: 11 pages, 10,763 rows, terminated `exhausted`.

## One token cache per credential

A Site's key is refused by another Site with HTTP 403, proved both directions. So
an install serving two LeagueApps accounts needs two credentials — and a token
cache keyed by credential.

Caching under one global key means the first account to authenticate populates it,
the second reuses that token, and LeagueApps answers 403. It reads as a permissions
problem when the fault is that the wrong key signed the request.

## Still no schedules or standings

Re-probed with a valid token on both hosts:

```
api.leagueapps.io/v2/sites/{site}/programs/{id}/schedule     404
api.leagueapps.io/v2/sites/{site}/programs/{id}/games         404
api.leagueapps.io/v2/sites/{site}/programs/{id}/standings     404
api.leagueapps.io/v2/sites/{site}/programs/{id}/teams         404
api.leagueapps.io/v2/sites/{site}/locations                   404
admin.leagueapps.io/v2/sites/{site}/export/schedule           404
admin.leagueapps.io/v2/sites/{site}/export/standings          404
```

Two endpoints exist on this credential type: `export/registrations-2` and
`export/programs`. Teams are derived from registrations. Schedules and standings
cannot be built from what a Private API Key reaches.
