# Divisions

The single thing that varies most between LeagueApps Sites, and the thing this
plugin was rewritten to stop assuming.

## Two models, both real

LeagueApps supports divisions two ways, and a Site may use either or both.

| Model | What it looks like | Where the division is |
|---|---|---|
| **Divisions inside one program** | One "Summer Classic 2026" program, teams assigned to A, B, C | The registration's `division` field |
| **A separate program per division** | "Summer Classic 2026 (C Division)" as its own program | The program's name |

Both are ordinary configurations. Which one a Site uses depends on whether the
groups need different registration, pricing, forms, deadlines, or staff
permissions — separate programs when they do, divisions when they do not.

**Do not restructure a Site to suit this plugin.** It reads whatever is there.

## Order of authority

```
1. The `division` field, when set. Explicit, set by the organiser, always wins.
2. The program name, when the field is empty. This is inference.
3. Neither matched -> the team is HELD, never guessed.
```

The third case is the one that matters most. A `division` value that is set but
**unrecognised** is not the same as no value at all:

```php
if ( '' !== $explicit ) {
    $key = $this->map->find_in( $explicit );
    if ( null !== $key ) {
        return DivisionMatch::known( ... );
    }

    // Do NOT fall back to the program name. The organiser named something
    // specific, and inferring past it publishes the team in a division
    // nobody put it in.
    return DivisionMatch::unrecognised( $explicit, 'division_field' );
}
```

Deleting that fallback guard fails 7 tests.

## Run discovery first

```bash
wp leagueapps discover --site=1234
```

It reports which model the Site uses, every division value with a team count, and
everything the current map does not match.

The threshold is generous in both directions and anything between is reported as
`mixed` rather than forced into an answer. Measured across two live Sites the
`division` field is populated **0%** and **11%** of the time — and 11% is not
"uses in-program divisions" or "does not". It is a Site that does both and needs
a person to look.

## The map is configuration, not code

This started as a hardcoded list of A through E, Women's and Legends, which is one
league's vocabulary and nobody else's. A public plugin cannot ship that as a
constant.

So the map is data. Discovery proposes, an operator confirms a public label and
order, and `DivisionMap` is built from the result. `presets/` holds starting
points, and nothing loads one unless somebody picks it.

```php
array(
    'key'     => 'legends',
    'label'   => 'Legends Division',
    'order'   => 80,
    'aliases' => array( 'legends d', 'legends', 'masters', 'master' ),
    'visible' => true,
)
```

## Aliases match longest first

This is load-bearing, not an optimisation.

`"Legends D"` contains `"d"`. `"A/B"` contains `"a"`. `"Open C"` contains `"c"`.
Matching in declaration order meant hand-ordering the map so compound entries came
before single letters — which worked for one league and broke silently the first
time somebody added an entry in the wrong place.

Longest-first makes the ordering a property of the data. A map can now be written
in any order.

## Word boundaries, not substrings

```
"2022 Open End of Season Tournament"
```

contains `"open e"` and was read as E Division. Boundary-matched, it is not a
division at all.

```php
'/(?<![a-z0-9])' . preg_quote( $alias, '/' ) . '(?![a-z0-9])/'
```

The look-arounds exclude letters and digits but not `/` or `-`, so `a/b` and
`legends-d` still match. Removing them fails 11 tests.

Found by a test, not in production. That is the argument for the test.

## Real variation this handles

Every one of these appears in live data:

```
2026 Summer Classic (C Division)     tournament, division in brackets
Open D Division Fall 2026            league, season last
2019 Spring Open D Division          league, season first
Spring 2018 Womens Division          no apostrophe
Spring 2026 Women's Division         curly apostrophe
Open Master Division 2015            singular, and an old name
2016 Fall Masters Division           plural, same division
Legends D                            graded, same division
```

Apostrophes are normalised, so `Women's`, `Women's` and `Womens` all land in one
place. An operator typing an alias will use whichever their keyboard produces.

### Renamed divisions collapse

A league that renamed Masters to Legends has both in its history, and may also
carry "Legends D" from when it was graded. The preset collapses all of them, so a
decade of programs sits under one heading instead of splitting across
near-duplicate labels.

If a Site genuinely runs Legends **and** Legends D as separate competitions, split
the entry into two. Ours does not.

## Cross-division play

LeagueApps documents Cross-Program Games between teams in different programs, with
a program setting controlling whether those results count toward standings. Within
one program using internal divisions, the scheduling behaviour is less clearly
documented — verify it in the admin before relying on it.

None of this affects the plugin today, because there is no schedule or standings
endpoint to read. When there is, the schema must not assume `home division ==
away division`: store a division per side and a game type, and display the
official classification rather than inferring one.

Do not calculate standings in WordPress. Tie-breaks, forfeits, crossovers and
bracket play all change the answer, and a table that disagrees with the official
one is worse than no table.
