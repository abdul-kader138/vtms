# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Customers (primary for the customer side):** Italian sole traders in Regime Forfettario — artigiani, commercianti and Gestione Separata freelancers — who are clients of an accounting office. They open the portal to find out what they owe, when, and how to pay it; often on a phone, between work.
- **Office staff:** accountants who run calculations, import income and record payments in the admin panel (Filament). Out of scope for the customer side.

## Product Purpose

VTMS is the accounting office's tax management system. The customer side shows each customer their own position for a tax year: what to pay next (or what is overdue), how the year is going against the €85,000 revenue limit, their documents, and what each charge is. Success: a customer knows within seconds what to pay, by when, with which F24 codes, and stops calling the office to ask.

## Positioning

The figures are the accountant's own calculations for this customer (flat tax 5%/15%, INPS, INAIL, stamp duty, Diritto Annuale) with real F24 codes and deadlines that follow Italian rules — not a generic tax calculator.

## Operating Context

- Payments are made with the F24 form (home banking or the accountant); each line has a code (1790/1791/1792, AF/CF/AP/CP, PXX/P10, 2521–2524, 3850).
- Deadlines cluster on 16 May, 30 June, 20 Aug, 16 Nov, 30 Nov, 16 Feb and quarter ends; overdue items need a clear path to settle.
- The office links a customer login to the customer record; until then the account sees no figures.
- Languages: Italian and English (Bengali exists for staff UI); customers mostly read Italian.

## Capabilities and Constraints

- Customer routes: `/login`, `/register` (old `/app/login`, `/app/register` redirect), `/reset-password`, `/verify-email`, `/email-verified`, `/auth/callback`, `/app/tax` (React 19 + Vite, Sanctum SPA session, Blade shells).
- Read-only for customers: they cannot change figures or record payments.
- Sign-in options: email + password, Google, Turnstile captcha when configured, 2FA and device lock on the panel login.
- Public registration stays open; an unlinked account must be told plainly that the office has to link it.
- Separate sign-ins and dashboards (user decision, Nov 2026): staff use `/panel/login` → `/panel` (Filament, admin look); customers use `/login` → `/app/tax`. Signing in on the other page still works and lands on your own dashboard; `/` redirects each account to its dashboard.

## Brand Commitments

- Product name VTMS (configurable `app_name` setting and logo).
- The customer side gets its own visual identity, distinct from the admin panel (user decision, Nov 2026).

## Evidence on Hand

- Demo customers and logins from `DemoTaxDataSeeder` (fictional, labelled demo). No testimonials, customer counts or marketing claims exist — none may be invented.

## Product Principles

1. The next action first: what to pay, how much, by when, how.
2. Accountant-grade accuracy, said in plain words; every code and date is real.
3. Calm about money: overdue is clear and actionable, never alarming theatre.
4. Italian first, English equal.

## Accessibility & Inclusion

WCAG 2.1 AA contrast and keyboard access; works one-handed on a 390px phone.
