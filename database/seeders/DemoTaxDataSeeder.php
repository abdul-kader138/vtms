<?php

namespace Database\Seeders;

use App\Enums\TaxPaymentType;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsPayment;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\HistoricalYearEntryService;
use App\Services\Tax\TaxCalculationOrchestrator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * Fictional demo data that exercises every tax feature: one customer per
 * INPS category, a full previous year (calculated) and the current year so
 * far (live estimate + prediction), a manually backfilled historical year,
 * payments in every status, and a verified customer-portal login per
 * customer (password: "password").
 *
 * Only called from DatabaseSeeder in local/testing. Run it elsewhere with
 * `php artisan db:seed --class=DemoTaxDataSeeder`. Skips itself if the demo
 * customers already exist, so it is safe to re-run.
 */
class DemoTaxDataSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (Customer::where('vat_no', 'like', 'DEMO%')->exists()) {
            $this->command?->info('Demo tax data already present — skipped.');

            return;
        }

        $previous = (int) now()->year - 1;
        $current = (int) now()->year;
        // Complete months only: the current month is still in progress.
        $monthsSoFar = max(1, (int) now()->month - 1);

        $artigiano = Customer::create([
            'name' => 'Luca Ferri',
            'company' => 'Bottega Ferri',
            'vat_no' => 'DEMO00000001',
            'ateco_code' => '95.29.09', // repairs → "other activities", 67%
            'email' => 'bottega.ferri@example.com',
            'phone' => '+39 055 000 0001',
            'address' => 'Via dei Fabbri 12',
            'city' => 'Firenze',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 67.00,
            'tax_rate' => 5.00,
            'business_start_date' => ($previous - 1).'-01-01',
            'inps_discount_eligible' => true,
            'inail_ateco_code' => '43.31',
            'inail_rate' => 5.00,
            'inail_minimum_payment' => 210.00,
        ]);

        $commerciante = Customer::create([
            'name' => 'Giulia Conti',
            'company' => 'Emporio Conti',
            'vat_no' => 'DEMO00000002',
            'ateco_code' => '47.71.10', // clothing retail → trade, 40%
            'email' => 'emporio.conti@example.com',
            'phone' => '+39 051 000 0002',
            'address' => 'Corso Italia 48',
            'city' => 'Bologna',
            'customer_type' => 'commercianti',
            'coefficient_of_profitability' => 40.00,
            'tax_rate' => 5.00,
            'business_start_date' => '2023-03-01',
        ]);

        // Started in the previous year: demonstrates "no balance payment in
        // the business-start year".
        $professionista = Customer::create([
            'name' => 'Marco Marino',
            'company' => 'Studio Marino',
            'vat_no' => 'DEMO00000003',
            'ateco_code' => '74.10.29', // design → professional, 78%
            'startup_rate_eligible' => true,
            'email' => 'studio.marino@example.com',
            'phone' => '+39 02 000 0003',
            'address' => 'Via Torino 7',
            'city' => 'Milano',
            'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00,
            'tax_rate' => 5.00,
            'business_start_date' => $previous.'-02-01',
        ]);

        // Monthly revenue; a little seasonal variation, no randomness.
        $this->seedIncome($artigiano, $previous, 12, 2600);
        $this->seedIncome($artigiano, $current, $monthsSoFar, 2900);
        $this->seedIncome($commerciante, $previous, 12, 5200);
        // Fast pace this year → "projected to exceed the limit" warning.
        $this->seedIncome($commerciante, $current, $monthsSoFar, 9300);
        $this->seedIncome($professionista, $previous, 11, 2100, startMonth: 2);
        $this->seedIncome($professionista, $current, $monthsSoFar, 2400);

        // Year before last for the artigiano, entered by hand (as if carried
        // over from the old system). Its advances toward the previous year
        // are then subtracted from that year's calculated balance.
        $this->seedHistoricalYear($artigiano, $previous - 1);

        $orchestrator = app(TaxCalculationOrchestrator::class);
        foreach ([$artigiano, $commerciante, $professionista] as $customer) {
            $orchestrator->runForCustomerAndYear($customer->fresh(), $previous);
        }

        $this->recordPayments($artigiano, $commerciante, $previous);

        Artisan::call('tax:mark-overdue');

        foreach ([[$artigiano, 'Luca', 'Ferri'], [$commerciante, 'Giulia', 'Conti'], [$professionista, 'Marco', 'Marino']] as [$customer, $first, $last]) {
            $demoUser = User::firstOrCreate(
                ['email' => 'demo.'.strtolower($last).'@example.com'],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                    'customer_id' => $customer->id,
                ]
            );
            $demoUser->markAsCustomer();
        }

        $this->command?->info('Demo tax data seeded.');
        $this->command?->table(['Customer', 'Type', 'Portal login', 'Password'], [
            ['Bottega Ferri', 'Artigiani', 'demo.ferri@example.com', self::PASSWORD],
            ['Emporio Conti', 'Commercianti', 'demo.conti@example.com', self::PASSWORD],
            ['Studio Marino', 'Gestione Separata', 'demo.marino@example.com', self::PASSWORD],
        ]);
    }

    /**
     * Mostly SDI e-invoices, plus a couple of small receipts per month from
     * the privati device — some under the €77.47 stamp-duty threshold, so
     * Fattura Tra Privati only counts the qualifying ones.
     */
    private function seedIncome(Customer $customer, int $year, int $months, float $monthlyBase, int $startMonth = 1): void
    {
        $n = 0;

        for ($month = $startMonth; $month < $startMonth + $months && $month <= 12; $month++) {
            $monthTotal = round($monthlyBase * (1 + 0.15 * sin($month / 2)), 2);
            $receipts = [45.00, 120.00];
            $invoices = [round(($monthTotal - array_sum($receipts)) * 0.6, 2)];
            $invoices[] = round($monthTotal - array_sum($receipts) - $invoices[0], 2);

            foreach ($invoices as $i => $amount) {
                $this->income($customer, 'sdi_fattura', Carbon::create($year, $month, 5 + $i * 12), $amount, ++$n);
            }
            foreach ($receipts as $i => $amount) {
                $this->income($customer, 'privati_device', Carbon::create($year, $month, 10 + $i * 10), $amount, ++$n);
            }
        }
    }

    private function income(Customer $customer, string $source, Carbon $date, float $amount, int $n): void
    {
        IncomeData::create([
            'customer_id' => $customer->id,
            'source' => $source,
            'transaction_date' => $date->toDateString(),
            'sales_amount' => $amount,
            'taxable_sales' => $amount,
            'sale_taxes' => 0,
            'reference_no' => sprintf('%s-%d-%04d', $source === 'sdi_fattura' ? 'FT' : 'RC', $date->year, $n),
            'external_id' => sprintf('DEMO-%d-%d-%04d', $customer->id, $date->year, $n),
            'tax_year' => $date->year,
            'meta' => ['demo' => true],
        ]);
    }

    private function seedHistoricalYear(Customer $customer, int $year): void
    {
        $next = $year + 1;

        app(HistoricalYearEntryService::class)->save($customer, $year, [
            'tax' => [
                'total_sales' => 28000.00,
                'taxable_income' => 18760.00,
                'tax_due' => 938.00,
                'advance_payments_made' => 0,
                'balance_payment' => 938.00,
                'next_year_advance_base' => 750.40,
            ],
            'tax_payments' => [
                ['payment_type' => TaxPaymentType::Balance->value, 'tax_year' => $year, 'amount' => 938.00, 'due_date' => "{$next}-02-16", 'status' => 'paid', 'paid_amount' => 938.00, 'paid_date' => "{$next}-02-12"],
                ['payment_type' => TaxPaymentType::FirstAdvance->value, 'tax_year' => $next, 'amount' => 375.20, 'due_date' => "{$next}-06-30", 'status' => 'paid', 'paid_amount' => 375.20, 'paid_date' => "{$next}-06-25"],
                ['payment_type' => TaxPaymentType::SecondAdvance->value, 'tax_year' => $next, 'amount' => 375.20, 'due_date' => "{$next}-11-30", 'status' => 'paid', 'paid_amount' => 375.20, 'paid_date' => "{$next}-11-28"],
            ],
            'inps' => [
                'taxable_income' => 18760.00,
                'inps_rate' => 21.00,
                'inps_amount' => 3939.60,
                'discount_percentage' => 35.00,
                'discount_amount' => 1378.86,
                'inps_amount_after_discount' => 2560.74,
                'calculation_method' => 'percentage',
            ],
            'inps_payments' => collect([[5, 16], [8, 20], [11, 16]])
                ->map(fn ($md, $i) => ['installment_number' => $i + 1, 'amount' => 640.19, 'due_date' => sprintf('%d-%02d-%02d', $year, $md[0], $md[1]), 'status' => 'paid', 'paid_amount' => 640.19])
                ->push(['installment_number' => 4, 'amount' => 640.17, 'due_date' => "{$next}-02-16", 'status' => 'paid', 'paid_amount' => 640.17])
                ->all(),
            'inail' => [
                'taxable_income' => 18760.00,
                'inail_rate' => 5.00,
                'inail_calculated_amount' => 938.00,
                'inail_minimum_payment' => 210.00,
                'inail_final_amount' => 938.00,
                'ateco_code' => '43.31',
            ],
            'inail_payment' => ['amount' => 938.00, 'due_date' => "{$next}-02-16", 'status' => 'paid', 'paid_amount' => 938.00],
            'diritto_annuale_payment' => ['amount' => 53.00, 'due_date' => "{$year}-03-31", 'status' => 'paid', 'paid_amount' => 53.00],
        ]);
    }

    /** A realistic mix: paid, partly paid, and untouched (→ overdue/pending). */
    private function recordPayments(Customer $artigiano, Customer $commerciante, int $year): void
    {
        InpsPayment::where('customer_id', $artigiano->id)->where('payment_year', $year)->where('installment_number', '<=', 2)
            ->get()
            ->each(fn (InpsPayment $p) => $p->update(['status' => 'paid', 'paid_amount' => $p->amount, 'paid_date' => $p->due_date]));

        $balance = TaxPayment::where('customer_id', $artigiano->id)->where('tax_year', $year)->where('payment_type', TaxPaymentType::Balance)->first();
        $balance?->update(['status' => 'paid', 'paid_amount' => $balance->amount, 'paid_date' => $balance->due_date->copy()->subDays(3)]);

        $commercianteBalance = TaxPayment::where('customer_id', $commerciante->id)->where('tax_year', $year)->where('payment_type', TaxPaymentType::Balance)->first();
        $commercianteBalance?->update(['paid_amount' => round((float) $commercianteBalance->amount / 2, 2), 'paid_date' => $commercianteBalance->due_date]);
    }
}
