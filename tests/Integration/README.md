# Integration suite

Empty on purpose, and declared on purpose.

`phpunit.xml.dist` has always listed this directory. It did not exist, so a bare
`vendor/bin/phpunit` exited with code 2 and the message
`Test directory "tests/Integration" not found` **before running anything**.
Somebody checking whether the suite passed got an error, not 197 passing tests,
and the obvious reading of that error is "the tests are broken."

## What belongs here

Only what WordPress itself owns, and only what cannot be proved without it:

- `WpdbTeamRepository` against a real `$wpdb`, including the `location` column
  that was in `Team` and in `visible_hash()` but not in the schema, which made
  the sync report 11 updates forever without converging.
- `Settings` sanitising and round-tripping an option, and bumping the generation
  when config changes.
- Capability checks on the admin screen and the `admin_post_*` handlers.
- Block and shortcode output rendered through WordPress rather than called
  directly.
- Cache invalidation: that enabling protection or changing a display setting
  purges the page, which needs a real object cache to observe.

## What does not

Anything in `src/Domain`, `src/Service` or `src/Contracts`. Those are plain PHP
and belong in `tests/Unit`, which runs in about 70ms because it loads nothing.
If a test you are writing for one of those classes seems to need WordPress, the
class has acquired a dependency it should not have.

## Running it

Needs the WordPress test library and a throwaway database, which is why it is
not wired into the default run yet:

```
vendor/bin/phpunit --testsuite unit          # every save
vendor/bin/phpunit --testsuite integration   # needs WP + a scratch DB
```
