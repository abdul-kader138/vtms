---
name: VTMS customer side — Metro line
scope: customer portal (/app/tax), sign-in/register/reset, status pages
source: resources/css/customer.css
---

# Design — customer side

The tax year is a metro line; each payment is a station. The world comes from Bob Noorda's Milan Metropolitana signage: white ground, one line colour, firm grotesque type. It lends type, palette, density and one signature move; layout and controls stay standard web patterns. The admin panel (Filament) keeps its own teal look and is out of scope.

## Colour (Restrained, one committed line colour)

| Token | Value | Job |
|---|---|---|
| `--line` | #007a37 | Bands, the line, primary buttons, focus ring, current position |
| `--line-strong` | #00652d | Green text/links on white (AA) |
| `--line-tint` / `-2` | #e5f2e9 / #cce6d5 | Success notices, selection, focus halo |
| `--ink` / `-2` / `-3` | #15191b / #454d51 / #657074 | Text, secondary, muted (all ≥4.5:1) |
| `--ground` / `--ground-2` | #fff / #f2f4f1 | Page / second neutral layer (panels, hover) |
| `--rule` / `--rule-strong` | #dde2dd / #b9c1bb | Dividers / input borders |
| `--overdue` / `-ink` / `-tint` | #c41e2e / #a3121f / #fcebec | Overdue only — never decoration |
| `--soon` / `-ink` / `-tint` | #d38b0b / #7f4c00 / #fff3dc | Due within 14 days, warnings |

Red is never used as a brand colour (it would collide with "overdue").

## Type

Schibsted Grotesk Variable, self-hosted via `@fontsource-variable/schibsted-grotesk`; one family for everything. Weights 400 / 600–650 (labels, rows) / 700–750 (headings, amounts). Lining, proportional figures — deliberately not tabular: Schibsted Grotesk's tabular set gives the thousands and decimal separators a full figure width ("€1, 411. 79"), which reads worse than imperfect digit alignment. Amount columns stay right-aligned with two decimals, so totals still line up. Headings tracking −0.02em (display −0.03em). Body 16px; labels 14px.

## Components

- **Band** (`.c-band`): green strip with the mark (logo or initial square), customer name, year select, IT/EN, sign out.
- **Code badge** (`.c-code`): F24 code as a black line-number square; dashed when there is no code.
- **The line** (`.c-line`, `.c-stop`): vertical 6px green rail; stations in date order. Paid = small ring, upcoming = white dot with green ring, next = larger ring, overdue = red dot on a red-tint row, cancelled = grey dot. A black "Today" bar marks where you are. A station button expands its details inline.
- **Next stop** (`.c-next`): the overdue total (red tint) or the next deadline (neutral) or all-paid (green tint); amount is the largest type on the page.
- **Meter** (`.c-meter`): revenue against the limit; lighter fill for the projection; amber above 80%, red above the limit.
- **Figures** (`.c-figures`): two-column definition list with rules, not stat cards.
- **Auth shell** (`.c-auth`): green side panel with a three-station route (hidden on mobile), form panel ≤420px; tabs underline in green.
- Buttons 44px (36px small), radius 6px; panels radius 12px; inputs 46px, 16px text (no iOS zoom).

## Motion

150–250ms ease-out on hover/focus and the station expand (grid-rows transition); skeleton shimmer while loading; everything off under `prefers-reduced-motion`.

## Rules

- No stat-card grids, no gradients, no eyebrows/kickers, no cards in cards.
- Every interactive element has hover, focus-visible (2px green outline), disabled, loading states.
- Copy names the action; errors say what to do. Italian first (lang/it.json), English equal.
