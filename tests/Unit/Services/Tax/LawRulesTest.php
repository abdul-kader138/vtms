<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\DirittoAnnualePayment;
use App\Models\FatturaTraPrivatiPayment;
use App\Models\IncomeData;
use App\Models\InpsCalculation;
use App\Models\InpsPayment;
use App\Models\Setting;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use App\Services\Tax\PaymentScheduleService;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Support\Tax\AtecoGroup;
use App\Support\Tax\F24Code;
use App\Support\Tax\ItalianCalendar;
use App\Support\Tax\TaxRules;
use Database\Seeders\DirittoAnnualeBracketSeeder;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LawRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');
    }

    private function customer(array $attrs = []): Customer
    {
        return Customer::create($attrs + ['name' => 'Law', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);
    }

    private function income(Customer $c, string $date, float $amount): void
    {
        IncomeData::create([
            'customer_id' => $c->id, 'source' => 'sdi_fattura', 'transaction_date' => $date,
            'sales_amount' => $amount, 'taxable_sales' => $amount, 'sale_taxes' => 0, 'tax_year' => (int) substr($date, 0, 4),
        ]);
    }

    private function taxCalc(Customer $c, int $year, float $taxDue, ?float $balance = null): TaxCalculation
    {
        return TaxCalculation::create([
            'customer_id' => $c->id, 'tax_year' => $year, 'total_sales' => 0, 'taxable_income' => 0, 'coefficient_used' => 40,
            'tax_rate_used' => 15, 'tax_due' => $taxDue, 'advance_payments_made' => 0, 'balance_payment' => $balance ?? $taxDue, 'next_year_advance_base' => $taxDue,
        ]);
    }

    public function test_calendar_moves_deadlines_off_weekends_and_holidays(): void
    {
        $this->assertSame('2026-04-05', ItalianCalendar::easter(2026)->toDateString());
        $this->assertTrue(ItalianCalendar::isHoliday(ItalianCalendar::easter(2026)->addDay()));
        $this->assertSame('2024-07-01', ItalianCalendar::deadline(2024, 6, 30)->toDateString()); // Sunday
        $this->assertSame('2025-06-30', ItalianCalendar::deadline(2025, 6, 30)->toDateString()); // Monday
        $this->assertSame('2026-06-03', ItalianCalendar::deadline(2026, 6, 2)->toDateString()); // Festa della Repubblica
    }

    public function test_ateco_codes_map_to_their_legal_coefficient(): void
    {
        $this->assertSame(86.0, AtecoGroup::forAtecoCode('43.21.01')->coefficient());
        $this->assertSame(40.0, AtecoGroup::forAtecoCode('47.11')->coefficient());
        $this->assertSame(40.0, AtecoGroup::forAtecoCode('47.81.01')->coefficient());
        $this->assertSame(54.0, AtecoGroup::forAtecoCode('47.89.09')->coefficient());
        $this->assertSame(62.0, AtecoGroup::forAtecoCode('46.18.99')->coefficient());
        $this->assertSame(67.0, AtecoGroup::forAtecoCode('62.01.00')->coefficient()); // software: "other activities"
        $this->assertSame(78.0, AtecoGroup::forAtecoCode('69.20.11')->coefficient());
        $this->assertSame(40.0, AtecoGroup::forAtecoCode('56.10.11')->coefficient());
        $this->assertSame(67.0, AtecoGroup::forAtecoCode('96.02.01')->coefficient());
        $this->assertNull(AtecoGroup::forAtecoCode('abc'));
    }

    public function test_startup_rate_is_5_percent_for_five_years_then_15(): void
    {
        $c = $this->customer(['startup_rate_eligible' => true, 'business_start_date' => '2022-03-01', 'tax_rate' => 5]);

        $this->assertSame(5.0, $c->taxRateFor(2022));
        $this->assertSame(5.0, $c->taxRateFor(2026));
        $this->assertSame(15.0, $c->taxRateFor(2027));
        $this->assertSame(2026, $c->startupRateEndsAfter());
        $this->assertSame(12.5, $this->customer(['tax_rate' => 12.5])->taxRateFor(2026));
    }

    public function test_inps_paid_during_the_year_is_deducted_but_inps_itself_uses_gross_income(): void
    {
        $c = $this->customer();
        $this->income($c, '2025-03-01', 50000); // gross 20,000

        // INPS paid in 2025 (installments of the 2024/2025 contributions).
        $calc = fn (int $y) => InpsCalculation::create(['customer_id' => $c->id, 'tax_year' => $y, 'taxable_income' => 0, 'inps_rate' => 0, 'inps_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'inps_amount_after_discount' => 0, 'calculation_method' => 'percentage'])->id;
        $prev = $calc(2024);
        $curr = $calc(2025);
        InpsPayment::create(['inps_calculation_id' => $prev, 'customer_id' => $c->id, 'payment_year' => 2024, 'installment_number' => 4, 'amount' => 1000, 'paid_amount' => 1000, 'paid_date' => '2025-02-14', 'status' => 'paid', 'due_date' => '2025-02-17']);
        InpsPayment::create(['inps_calculation_id' => $curr, 'customer_id' => $c->id, 'payment_year' => 2025, 'installment_number' => 1, 'amount' => 1500, 'paid_amount' => 1500, 'paid_date' => '2025-05-16', 'status' => 'paid', 'due_date' => '2025-05-16']);
        // Paid in 2026 — not deductible in 2025.
        InpsPayment::create(['inps_calculation_id' => $curr, 'customer_id' => $c->id, 'payment_year' => 2025, 'installment_number' => 2, 'amount' => 1500, 'paid_amount' => 1500, 'paid_date' => '2026-01-10', 'status' => 'paid', 'due_date' => '2025-08-20']);

        $result = app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);
        $tc = $result['tax'];

        $this->assertSame('20000.00', $tc->gross_income);
        $this->assertSame('2500.00', $tc->inps_deducted);
        $this->assertSame('17500.00', $tc->taxable_income);
        $this->assertSame('2625.00', $tc->tax_due);
        $this->assertSame('2625.00', $tc->next_year_advance_base); // 100%
        $this->assertSame('law', $tc->rules);
        $this->assertSame('20000.00', InpsCalculation::where('customer_id', $c->id)->where('tax_year', 2025)->value('taxable_income'));
    }

    public function test_advances_follow_the_thresholds_and_40_60_split(): void
    {
        $schedule = app(PaymentScheduleService::class);

        $small = $this->customer(['name' => 'Small']);
        $schedule->scheduleTaxPayments($this->taxCalc($small, 2025, 40.00), $small);
        $this->assertFalse(TaxPayment::where('customer_id', $small->id)->whereIn('payment_type', ['first_advance', 'second_advance'])->exists());

        $medium = $this->customer(['name' => 'Medium']);
        $schedule->scheduleTaxPayments($this->taxCalc($medium, 2025, 200.00), $medium);
        $this->assertFalse(TaxPayment::where('customer_id', $medium->id)->where('payment_type', 'first_advance')->exists());
        $single = TaxPayment::where('customer_id', $medium->id)->where('payment_type', 'second_advance')->first();
        $this->assertSame('200.00', $single->amount);
        $this->assertSame('2026-11-30', $single->due_date->toDateString());

        $large = $this->customer(['name' => 'Large']);
        $schedule->scheduleTaxPayments($this->taxCalc($large, 2025, 1000.01), $large);
        $first = TaxPayment::where('customer_id', $large->id)->where('payment_type', 'first_advance')->first();
        $second = TaxPayment::where('customer_id', $large->id)->where('payment_type', 'second_advance')->first();
        $this->assertSame('400.00', $first->amount);
        $this->assertSame('600.01', $second->amount);
        $this->assertSame(2026, $first->tax_year);
        $this->assertSame('2026-06-30', $first->due_date->toDateString());
        $this->assertSame('2026-06-30', TaxPayment::where('customer_id', $large->id)->where('payment_type', 'balance')->first()->due_date->toDateString());
    }

    public function test_start_year_balance_is_scheduled_under_law_rules(): void
    {
        $c = $this->customer(['business_start_date' => '2025-02-01']);
        app(PaymentScheduleService::class)->scheduleTaxPayments($this->taxCalc($c, 2025, 900.00), $c);

        $this->assertSame('900.00', TaxPayment::where('customer_id', $c->id)->where('payment_type', 'balance')->where('tax_year', 2025)->value('amount'));
    }

    public function test_stamp_duty_is_quarterly_with_small_amounts_deferred_to_november(): void
    {
        $c = $this->customer();
        foreach (['2025-01-10', '2025-02-10', '2025-05-10', '2025-08-10', '2025-08-11', '2025-12-01'] as $d) {
            $this->income($c, $d, 100);
        }
        $this->income($c, '2025-03-15', 50); // under €77.47 — not counted

        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);

        $rows = FatturaTraPrivatiPayment::where('customer_id', $c->id)->orderBy('quarter')->get();
        $this->assertSame([1, 2, 3, 4], $rows->pluck('quarter')->all());
        $this->assertSame(['4.00', '2.00', '4.00', '2.00'], $rows->pluck('amount')->all());
        // Q1+Q2 ≤ €5,000 → paid with Q3 on 1 Dec 2025 (30 Nov is a Sunday).
        $this->assertSame(['2025-12-01', '2025-12-01', '2025-12-01', '2026-03-02'], $rows->map(fn ($r) => $r->due_date->toDateString())->all());
        $this->assertSame(['2521', '2522', '2523', '2524'], $rows->map(fn ($r) => F24Code::for('fattura_tra_privati', $r))->all());
    }

    public function test_switching_rules_removes_only_untouched_rows(): void
    {
        Setting::set(TaxRules::SETTING, TaxRules::LEGACY, 'tax');
        $c = $this->customer();
        $this->income($c, '2025-03-01', 1000);
        $this->income($c, '2025-04-01', 1000);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);
        $this->assertSame([0], FatturaTraPrivatiPayment::where('customer_id', $c->id)->pluck('quarter')->all());

        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);
        $this->assertSame([1, 2], FatturaTraPrivatiPayment::where('customer_id', $c->id)->orderBy('quarter')->pluck('quarter')->all());

        // A paid quarterly row survives switching back.
        FatturaTraPrivatiPayment::where('customer_id', $c->id)->where('quarter', 1)->update(['status' => 'paid', 'paid_amount' => 2]);
        Setting::set(TaxRules::SETTING, TaxRules::LEGACY, 'tax');
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);
        $this->assertSame([0, 1], FatturaTraPrivatiPayment::where('customer_id', $c->id)->orderBy('quarter')->pluck('quarter')->all());
    }

    public function test_recalculating_never_rewrites_a_settled_payment(): void
    {
        Setting::set(TaxRules::SETTING, TaxRules::LEGACY, 'tax');
        $c = $this->customer(['business_start_date' => '2020-01-01']);
        $this->income($c, '2025-03-01', 20000);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);

        $balance = TaxPayment::where('customer_id', $c->id)->where('payment_type', 'balance')->firstOrFail();
        $balance->update(['status' => 'paid', 'paid_amount' => $balance->amount, 'paid_date' => '2026-02-10']);
        $original = [$balance->amount, $balance->due_date->toDateString()];

        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);

        $balance->refresh();
        $this->assertSame($original, [$balance->amount, $balance->due_date->toDateString()]);
        $this->assertSame('paid', $balance->status->value);
        // Unsettled rows still follow the new rules.
        $this->assertSame('2026-06-30', TaxPayment::where('customer_id', $c->id)->where('payment_type', 'first_advance')->first()->due_date->toDateString());
    }

    public function test_diritto_annuale_is_due_30_june_under_law_rules(): void
    {
        $this->seed(DirittoAnnualeBracketSeeder::class);
        $c = $this->customer();
        $this->income($c, '2025-03-01', 1000);

        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($c, 2025);

        $this->assertSame('2025-06-30', DirittoAnnualePayment::where('customer_id', $c->id)->first()->due_date->toDateString());
    }
}
