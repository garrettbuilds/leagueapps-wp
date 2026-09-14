# Divisions

The one thing that varies most between LeagueApps Sites. Read this before changing the
detector.

## LeagueApps supports two models, and this plugin handles both

### Divisions inside one Program

The organiser enables Divisions on a Program and assigns Teams to them. The Registration then
carries an explicit `division` value.

```
Program:   Summer Classic 2026
Divisions: A, B, C, D, E, Women's, Legends
```

This is usually the better configuration when the groups share registration, pricing and rules,
and it gives you one Program ID to integrate against instead of seven.

### A separate Program per Division

Each Division is its own Program, and the Division name lives inside the Program name.

```
2026 Summer Classic (C Division)      TOURNAMENT, one parent plus a child per Division
Open D Division Fall 2026            LEAGUE, free-standing per Season
```

This is what you get when Divisions genuinely differ in dates, pricing, forms or ownership, and
it is also what many older Sites have simply because it predates in-Program Divisions.

## How the plugin decides

In order of authority:

1. **The `division` field**, when set. Explicit, chosen by the organiser, always wins.
2. **The Program name**, when the field is empty. Inference, and only a fallback.

```php
LAWP_Divisions::detect( $program_name, $division_field );
// returns [ 'key', 'label', 'order', 'source' => 'division_field' | 'program_name' ]
```

**If the field is set but unrecognised, the Team is held, not guessed.** The organiser said
something specific; falling back to the Program name would override a deliberate choice and put
a Team somewhere it did not ask to be.

Run `wp leagueapps discover` against a new Site. It reports which model is in use, what
percentage of Registrations carry the field, which Divisions exist, and which Programs would be
skipped, so an administrator confirms the mapping instead of the plugin assuming one.

## Program names are inconsistent across a decade

Real names from two live Sites:

```
2026 Summer Classic (C Division)      Division in brackets, year first
Open D Division Fall 2026            Division first, Season last
2019 Spring Open D Division          year first, Season second
Spring 2018 Womens Division          no apostrophe
Spring 2017 Women Division           singular
Spring 2016 Open Masters Division    the old name for Legends
Spring 2017 Open Master Division     the old name, singular
```

So the detector matches the Division token **wherever it appears**, rather than assuming a
position. Verified against all 227 Program names across both Sites.

## Naming rules encoded here

**Masters is Legends.** The Division was renamed. Older Programs still say Masters, singular and
plural, and all map to Legends rather than splitting a decade of history across two labels.

**Legends D is just Legends.** Source data carries `Legends D Division`, `Legends`, `Masters D`
and `Master`. In practice it is called Legends, so all collapse to one. If a Site runs Legends
*and* Legends D as genuinely separate competitions, split the entry in
`class-lawp-divisions.php`.

**Word boundaries, not substrings.** `2022 Open End of Season Tournament` contains `open e` and
was being read as E Division. Caught by a test, which is the argument for keeping the test.

## What is skipped, and why that is correct

Programs matching no Division are skipped. Across two Sites that is 14 of 67 and 18 of 160:

```
2017 Open (Association) Tournament        an external tournament
Free Agents                          a holding Program, not a Division
Fall 2016 Open D1 Division           a short-lived split of D
2019 End of Season - Open Division   a post-season event
Spring 2019 Ratings Clinics          not competition at all
FB Test / 2020 TEST                  somebody testing the platform
```

**Skipped, never bucketed into "Other".** A Team shown in the wrong Division is worse than a
Team not shown, and an "Other" heading on a public page invites somebody to fix the data by
hand instead of fixing the Program name.

## Adding a Division

```php
'f' => array(
    'label' => 'F Division',
    'order' => 65,
    'match' => array( 'open f', 'f division', 'division f', 'f' ),
),
```

Then add the key to the ordering pass in `match_text()`. **Longer, more specific aliases must be
tested before shorter ones**, which is why `legends` and `womens` are checked before the single
letters: otherwise `Legends D` matches `d`.
