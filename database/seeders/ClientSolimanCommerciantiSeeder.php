<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Enums\IncomeSource;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsPayment;
use App\Models\Setting;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\TaxRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * A real Commercianti (regime forfettario, Gestione IVS) client, built from
 * the source documents in the gitignored docs/ folder — NOT fabricated:
 *
 *  - docs/S20??_?.csv (12 files): the corrispettivi telematici (RT device
 *    "3CICE147483") exported from the merchant's own cash register for
 *    every quarter of 2023, 2024 and 2025 — real daily sales.
 *  - docs/2025.pdf, docs/2026.pdf: "Cassetto fiscale" F24 payment history
 *    for "SOLIMAN MOHAMMED", listing the INPS quarterly installments
 *    actually paid in 2025 and 2026.
 *
 * Why this needs the "law" rule set, not a flat/legacy slab:
 * Artigiani/Commercianti INPS (Gestione IVS) is never a single flat
 * percentage of income. By law (see InpsContributionRuleSeeder) it is a
 * FIXED annual amount on a minimum income (the "minimale", due in 4 equal
 * installments on 16 May / 20 Aug / 16 Nov / 16 Feb regardless of how much
 * the business actually made) PLUS a percentage on any income above the
 * minimale, up to a ceiling (massimale). The real payments in the PDFs
 * line up almost exactly with App\Services\Tax\InpsCalculationService's
 * fixedPlusPercentage() method under a 35% start-up discount — confirming
 * that method against real F24 evidence is the point of this seeder. The
 * legacy app/database/seeders/InpsRateSlabSeeder.php rows, by contrast,
 * only encode a flat income-bracket percentage table with no minimale
 * concept — using them for this customer type produces the wrong figure,
 * which is exactly what TaxRules::LAW mode + InpsContributionRule exists
 * to fix (see InpsCalculationService::calculateForYear).
 *
 * Only the dates/amounts of INPS payments actually seen in the PDFs are
 * marked "paid"; everything else the engine computes is left as its normal
 * pending/overdue schedule rather than invented.
 *
 * Skips itself if the docs/ folder isn't present (it's gitignored — real
 * client paperwork never ships with the repo) or if the customer already
 * exists.
 */
class ClientSolimanCommerciantiSeeder extends Seeder
{
    private const VAT_PLACEHOLDER = 'CLIENT-SOLIMAN-MOHAMMED';

    public function run(): void
    {
        $docsPath = base_path('docs');

        if (! is_dir($docsPath)) {
            $this->command?->warn('docs/ not found — skipping ClientSolimanCommerciantiSeeder (source documents are gitignored, local-only).');

            return;
        }

        if (Customer::where('vat_no', self::VAT_PLACEHOLDER)->exists()) {
            $this->command?->info('Soliman Mohammed demo client already present — skipped.');

            return;
        }

        $this->call(InpsContributionRuleSeeder::class);

        $customer = $this->createCustomer();
        $years = $this->importIncome($customer, $docsPath);

        $originalRules = Setting::get(TaxRules::SETTING, TaxRules::LEGACY);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');

        try {
            $orchestrator = app(TaxCalculationOrchestrator::class);

            // First pass: builds the normal fixed-installment schedule for
            // every year with data, plus the current year (fixed part does
            // not depend on income, so 2026 schedules correctly even before
            // this year's sales are in).
            foreach ([...$years, (int) now()->year] as $year) {
                $orchestrator->runForCustomerAndYear($customer->fresh(), $year);
            }

            $this->applyRealPayments($customer);

            // Second pass: the forfettario income tax deducts INPS actually
            // PAID during the calendar year (cash basis, L. 190/2014 c. 64)
            // — now that real paid_amount/paid_date values are recorded,
            // recalculate so the tax figures reflect them.
            foreach (array_filter($years, fn ($y) => $y >= 2024) as $year) {
                $orchestrator->runForCustomerAndYear($customer->fresh(), $year);
            }
        } finally {
            Setting::set(TaxRules::SETTING, $originalRules, 'tax');
        }

        Artisan::call('tax:mark-overdue');

        $this->command?->info('Soliman Mohammed (Commercianti) seeded from docs/ real data.');
    }

    /**
     * Fields confirmed by the source documents are set directly; everything
     * else (VAT number, codice fiscale, exact ATECO) is NOT in the "Cassetto
     * fiscale" F24 screenshots or the RT export, so it is left null rather
     * than invented. coefficient_of_profitability (40%, retail trade) and
     * the start-up 5% rate are inferred from the data itself, documented
     * below — an admin should confirm/replace them from the client's actual
     * registration once available.
     */
    private function createCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Mohammed Soliman',
            'vat_no' => self::VAT_PLACEHOLDER, // placeholder — real P.IVA not present in the source docs, replace once known
            // Retail trade, generic — inferred from the RT device's daily
            // cash-sale pattern (many small transactions, no invoices).
            // Confirm the exact ATECO code against the client's registration.
            'ateco_code' => '47.00.00',
            'customer_type' => CustomerType::Commercianti,
            'coefficient_of_profitability' => 40.00, // standard forfettario coefficient for commercio (trade)
            'tax_rate' => Customer::STANDARD_RATE,
            'startup_rate_eligible' => true,
            // The RT device's first transmission (S2023_1.csv, last row) logs
            // a "periodo di inattivita'" ending 31/01/2023 — the device's,
            // and so the business's, first real activity.
            'business_start_date' => '2023-01-31',
            // NOT confirmed against the client's Cassetto Previdenziale or
            // any domanda di riduzione contributiva — inferred only from
            // arithmetic: real paid installments / full computed fixed
            // contribution ≈ 65.7% across all 4 years, close to the 65% a
            // 35% reduction implies (off by ~1-1.5%, unexplained). Treat
            // inps_discount_eligible as a working hypothesis, not a fact,
            // until someone checks the real INPS record.
            'inps_discount_eligible' => true,
            'inps_discount_request_date' => null,
            'inps_discount_source' => 'INFERRED from F24 totals only (docs/2025.pdf, docs/2026.pdf) — paid ≈65.7% of full fixed contribution, close to but not exactly 65%. No Cassetto Previdenziale/domanda seen — verify before relying on this.',
        ]);
    }

    /**
     * Imports every non-zero daily sale from the 12 quarterly RT exports as
     * real IncomeData (source: privati_device — a corrispettivo telematico,
     * not an SDI invoice). Zero-amount rows are the device's daily
     * heartbeat/check-in, not sales, and are skipped.
     *
     * @return list<int> the years actually imported (2023, 2024, 2025)
     */
    private function importIncome(Customer $customer, string $docsPath): array
    {
        $years = [];
        $n = 0;

        foreach (glob($docsPath.'/*.csv') ?: [] as $file) {
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }

            fgetcsv($handle, 0, ';'); // header

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (count($row) < 7 || ! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $row[2], $m)) {
                    continue;
                }

                $amount = $this->parseItalianDecimal($row[4]);
                if ($amount <= 0) {
                    continue;
                }

                $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                $year = (int) $m[3];
                $years[$year] = $year;

                IncomeData::create([
                    'customer_id' => $customer->id,
                    'source' => IncomeSource::PrivatiDevice,
                    'transaction_date' => $date,
                    'sales_amount' => $amount,
                    'taxable_sales' => $this->parseItalianDecimal($row[5]),
                    'sale_taxes' => $this->parseItalianDecimal($row[6]),
                    'reference_no' => trim($row[0], " '"),
                    'external_id' => 'RT-3CICE147483-'.trim($row[0], " '"),
                    'tax_year' => $year,
                    'meta' => ['device' => trim($row[1], " '"), 'source_file' => basename($file)],
                ]);
                $n++;
            }

            fclose($handle);
        }

        $this->command?->info("Imported {$n} real corrispettivi rows across ".count($years).' years.');

        sort($years);

        return $years;
    }

    private function parseItalianDecimal(string $value): float
    {
        return (float) str_replace(',', '.', str_replace('.', '', trim($value)));
    }

    /**
     * Marks exactly the INPS installments evidenced in docs/2025.pdf and
     * docs/2026.pdf as paid, with their real amounts and dates. Nothing
     * else is touched — unevidenced past installments (2023 and 2024's
     * first three) stay on the engine's normal computed schedule and will
     * show as overdue, which is the honest state given no payment record
     * for them exists in the supplied documents.
     *
     * installment_number 1–4 = fixed minimale contributions, due 16 May /
     * 20 Aug / 16 Nov of payment_year, 16 Feb of payment_year + 1 (see
     * PaymentScheduleService::scheduleFixedPlusPercentage).
     */
    private function applyRealPayments(Customer $customer): void
    {
        $payments = [
            // payment_year, installment, paid_date, paid_amount
            [2024, 4, '2025-02-10', 742.12],
            [2025, 1, '2025-05-16', 747.78],
            [2025, 2, '2025-08-20', 747.78],
            [2025, 3, '2025-10-22', 747.78], // paid ahead of the 16 Nov 2025 deadline
            [2025, 4, '2026-02-13', 747.74],
            // 2026 installment 1 was submitted twice four days apart
            // (15/5/2026 €757.91, then 19/5/2026 €757.95) — recorded here
            // as the final corrected F24; both are noted.
            [2026, 1, '2026-05-19', 757.95],
            [2026, 2, '2026-08-24', 757.95],
        ];

        foreach ($payments as [$year, $installment, $date, $amount]) {
            $payment = InpsPayment::where('customer_id', $customer->id)
                ->where('payment_year', $year)
                ->where('installment_number', $installment)
                ->first();

            if ($payment === null) {
                continue;
            }

            if ($year === 2026 && $installment === 1) {
                $note = 'From docs/2026.pdf (Cassetto fiscale): first submitted 15/05/2026 for €757.91, corrected 19/05/2026 to €757.95 — the corrected amount is recorded here.';
            } else {
                $sourcePdf = $year === 2024 || ($year === 2025 && $installment === 4) ? 'docs/2025.pdf' : 'docs/2026.pdf';
                $note = "From {$sourcePdf} (Cassetto fiscale F24 payment history).";
            }

            $payment->update([
                'status' => PaymentStatus::Paid,
                'paid_amount' => $amount,
                'paid_date' => $date,
                'payment_method' => 'F24',
                'notes' => $note,
            ]);
        }
    }
}
