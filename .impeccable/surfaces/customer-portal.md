# Surface brief — customer side (portal + sign-in/register/status)

Targets: resources/views/customer/*.blade.php, resources/js/customer/*.jsx, resources/js/customer-*.jsx
Mode: Operate (portal, auth forms). Build path: code-led (no image generation; Operate surface).

Audience & job: Italian forfettario sole traders, often on a phone, checking what to pay, by when and with which F24 code; then how the year is going, documents, and what each charge means. Unlinked new accounts must understand they wait for the office.

## Direction contract

THESIS: The tax year is a metro line and each payment a station — the customer always knows where they are and what the next stop costs. Refuses the category default of stat-card grids over a payments table.

OWN-WORLD: Bob Noorda's Milan Metropolitana signage. White ground, a single M2-green line colour for bands, the line and current position; ink near-black; red reserved for overdue, amber for due soon. Firm grotesque (Schibsted Grotesk, self-hosted), tabular figures, square line-number style code badges, no cards-in-cards, no gradients.

STORY: Open → see the next stop (or the overdue total) with amount, date, F24 code → follow the line to see paid and upcoming stops → check the year against the €85k limit → grab documents → read what each charge is.

FIRST VIEWPORT: Green band header (VTMS, customer name, year, language, sign out). Below, "Next stop" panel full width: date, amount at 2rem, description, F24 code badge, how-to-pay link; overdue replaces it with a red panel listing the overdue stops. Then two columns: left the vertical line (stations = payments, filled = paid, ring = next, red = overdue); right "Your year" limit bar, figures, documents, charge explanations. Mobile: one column, line first.

FORM: Metro line — #1 of 7 (Milan Metro, Solari board, Olivetti, Frate Indovino almanac, revenue stamps, commercialista folders, quaderno). seed: none (unavailable) — Impeccable launcher not run (downloads a binary; declined), user chose from the presented hand.

Signature interaction: the station rows; selecting a station opens its detail inline (code, section, reference year, paid date, receipt); the "you are here" marker sits between paid and upcoming stops.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
