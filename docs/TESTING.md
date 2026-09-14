# Testing

```bash
composer install
composer test           # unit suite, no WordPress, no database, no network
```

99 tests, 276 assertions, about 30ms.

## Two suites, deliberately separate

**`tests/Unit`** needs no WordPress. Everything in `src/Domain`, `src/Service` and
`src/Contracts` is plain PHP with no WordPress function in it, which is what makes
that possible. If a unit test ever needs WordPress bootstrapped, the class under
test is in the wrong layer.

**`tests/Integration`** needs the WordPress test library and covers only what
WordPress owns: the `$wpdb` repository, settings sanitising, capabilities, block
output, transactions.

```bash
wp scaffold plugin-tests leagueapps-wp --ci=github
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer test:integration
```

## Nothing touches the network

`FakeClient` returns a canned `SourceResult` or throws. A test that needs a 503
asks for one. That is the only reliable way to assert what happens on a 503, and
it is why the failure paths are the best-covered part of the plugin.

## The three tests that matter most

1. **An unknown division holds the team**, or blocks the run, according to the
   event's policy. It is never guessed into a division.
2. **An incomplete or failed read plans no deactivation** and leaves the last
   validated list on the page.
3. **A dry run builds the full diff and writes nothing**, proved by a write
   counter rather than by comparing stored values.

That third one matters more than it looks. Asserting the stored teams are
unchanged is weaker: a write that puts back an identical value is still a write.
It bumps a timestamp, it is reported as a change, and on a live site it purges a
page cache. So the assertion is on the write count.

## Mutation testing found two dead guards

Tests passing is not evidence that the thing being tested does anything. After
the suite went green, each safety guard was deleted in turn to check the suite
noticed:

| Guard deleted | Suite |
|---|---|
| Circuit breaker | 2 failures |
| Word boundaries in alias matching | 11 failures |
| Unrecognised division falls back to the program name | 7 failures |
| `safe_to_apply` check in the applier | 1 failure |
| Plan staleness | 1 failure |
| Plan superseded | 1 failure |
| Transaction rollback | 1 failure |
| **Deactivation gate in the planner** | **all 99 passed** |
| **Blocked/failed re-check in the applier** | **all 99 passed** |

The last two were dead code. Both read like defence in depth and neither was
reachable: an earlier check already caught every case. They are gone now, because
untested protection is worse than none — it invites the next reader to trust it.

What replaced them is a property, asserted across every failure mode at once:
a plan that is not safe to apply never reaches the database. That runs through
the applier, which is the live guard, and it fails when the applier's guard is
removed.

## Fixtures

Named after behaviour, never after production exports. Every team name, program
name and Site id in `tests/Support/Fixtures.php` is invented.

Real ones were scrubbed from this repository before its first commit, and must
not come back. A fixture is committed, public and permanent. Never commit:

```
API keys, certificates, private responses,
roster or member data, player names or emails,
full data exports, URLs carrying credentials
```

## The privacy boundary is tested, not trusted

`test_denylisted_fields_never_reach_a_team` feeds a row carrying an email, a date
of birth, a phone number and a payment status, then asserts none of them appear
in the JSON-encoded result. `test_a_denylisted_field_reaching_the_planner_blocks_the_run`
asserts that if one ever does get that far, the run stops rather than continues.
