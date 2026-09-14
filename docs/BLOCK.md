# The Teams Block

Core blocks, core markup, no build step. This has to render the same inside
Kadence, a block theme, a classic theme, or a page builder nobody here has heard
of — so it assumes none of them.

## Two entry points, one renderer

```
[Block editor]   leagueapps-wp/teams
[Anything else]  [leagueapps_teams event="summer-classic-2026"]
```

Both call `TeamsRenderer`. The shortcode is not a legacy fallback; it is how this
works in a builder that does not use the block editor, which is most of the reason
a plugin like this gets published.

The block is server-rendered, so the editor preview comes from `ServerSideRender`
and is byte-identical to what a visitor gets. Nothing but attributes is stored in
post content, which means a sync updates every page the block sits on without
anyone reopening a post.

## No build step

`blocks/teams/editor.js` is plain JavaScript using `wp.element.createElement`
rather than JSX. The file in the repo is the file that runs: somebody debugging it
in a browser sees this source, and a fork can change a label without installing
node.

## Controls appear only for data that exists

Capabilities are measured from the cache, not declared:

```
Show manager name     only if manager names are actually in this event's data
Show player counts    only if any team has more than one registration
Schedules, standings  never, because those endpoints do not exist
```

A tournament where only the manager registers gives every team a roster count of
1. A column of 1s tells a visitor nothing, so the control is not offered rather
than offered and useless.

Where a field is present but patchy, the help text says so:

```
Found for 76 of 84 teams. The rest show a blank cell.
```

## Two gates on captain names

The block attribute **and** the event config must both allow it. A page editor
cannot publish names the event's data policy says no to:

```php
'show_captain' => ! empty( $attributes['showCaptain'] ) && $config->show_captain,
```

## Three states, three messages

| State | Visitor sees |
|---|---|
| Never synced | "Team information is being prepared." |
| Synced, no teams | "No teams are registered yet." |
| Synced, behind schedule | The list, plus "Recent registrations may take a short time to appear." |

Never an empty table, and never a technical reason. "HTTP 503 from the source" is
true, useless to a reader, and tells an attacker about the stack. The list on
screen is still the last one that passed every check, so the honest thing to say
is that it may be a little behind.

An editor gets more: a misconfigured block prints a diagnostic for
`edit_posts` and **nothing at all** for a visitor.

## Accessibility

- `<th scope="col">` on headers, `<th scope="row">` on the team name, so reading
  across a row says the team first
- A `<caption>` naming the division, visually hidden
- `<nav aria-label>` on the jump links, and the anchor id on the **heading** so a
  jump lands on it and a screen reader announces what moved
- Jump pills are 24px minimum, which is WCAG 2.2 SC 2.5.8. A pill of text on its
  own line-height measures about 21px and fails
- `scroll-margin-top` on division headings, so a sticky theme header does not
  cover the thing a jump link just moved to (SC 2.4.11)
- The table scrolls inside its own container; the page body never scrolls sideways
- `prefers-reduced-motion` respected on smooth scrolling

## Anchors are scoped to the event

```
#summer-classic-2026-a
```

not `#division-a`. Two events on one page would otherwise emit the same id twice
and the jump link would go to whichever rendered first.

## Styling

`blocks/teams/style.css` sets structure and accessibility floors, and leaves
colour, type and spacing to the theme. Everything draws from `currentColor`, so
it inherits correctly on a dark background too.

Block supports are turned on for colour, typography, spacing and alignment, so
the usual editor controls work without this plugin reimplementing any of them.

## Caching

The block reads a view model cached under a generation key:

```
lawp:v1:summer-classic-2026:teams:filled:g43
```

A miss is normal and falls back to the durable table. A failure is never cached —
an empty result stored here, then served to the page cache and then to a CDN, is
how a working site starts telling visitors there are no teams.
