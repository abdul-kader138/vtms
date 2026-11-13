# VTMS — Italian Tax Compliance Manager

A Laravel + Filament application for managing Italian "Regime Forfettario" tax compliance for self-employed/small-business customers: INPS, INAIL, Diritto Annuale, and Fattura Tra Privati (virtual stamp duty) calculation and payment tracking.

## What it does

- Tracks customers (businesses this app files taxes for) and their tax profile: contributor category (Gestione Separata / Commercianti / Artigiani), profitability coefficient, flat tax rate, and INAIL registration details.
- Imports income/sales data from two CSV/XLS source formats (SDI electronic invoices and privati/device-register exports) with per-row dedup.
- Calculates, per customer and tax year: the core flat tax, progressive INPS contributions against an admin-managed rate-slab table, INAIL premiums, Diritto Annuale (chamber-of-commerce fee) against an admin-managed revenue-bracket table, and Fattura Tra Privati stamp duty — all with admin-configurable thresholds/rates (INPS discount %, stamp-duty threshold/fee).
- Generates the correct payment schedule/installments for each of the five tax streams and tracks payment status, partial payments, and uploaded receipt PDFs (stored privately, served via signed staff-only links).
- Supports manual historical-year backfill for past years' figures.

## Two surfaces, one `users` table

- **Staff** manage everything above through the Filament admin panel (`/`), gated by Spatie roles/permissions (`filament-shield`).
- **Customers** (if a self-service login is ever needed for them) authenticate through a separate React + Sanctum API surface (`/app/login`, `/app/register`, `/api/v1/*`) that shares the same `users` table and session guard — "staff vs customer" is just a role/permission check, not a separate account system.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# set ADMIN_EMAIL / ADMIN_PASSWORD in .env before seeding
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

## Testing

```bash
php artisan test
```

## Provenance

This app's tax-compliance logic was independently reimplemented from the *business requirements* of a legacy, commercially-licensed PHP application — no code from that original application was copied or ported. Its authentication/user/role/settings foundation was adapted from an internal Laravel + Filament skeleton, stripped of unrelated domain features.
