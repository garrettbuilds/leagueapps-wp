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
