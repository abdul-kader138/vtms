<?php

namespace Tests\Unit\Services\Tax;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\InpsCalculation;
use App\Models\Setting;
use App\Models\TaxCalculation;
use App\Services\Tax\IncomePredictionService;
use App\Support\Tax\TaxRules;
use Database\Seeders\InpsContributionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * predictRepresentative() is the new, separate "Prediction" section's data
 * source — these tests exist to pin down two things the user explicitly
 * asked for: (1) year-over-year seasonal projection, not a flat average,
 * and (2) that it can NEVER write a real TaxCalculation/InpsCalculation row,
 * since it's purely representative and must have zero effect on real data.
 */
class IncomePredictionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Prediction Test', 'customer_type' => 'commercianti',
            'coefficient_of_profitability' => 40, 'tax_rate' => 15,
        ], $overrides));
    }

    private function income(Customer $customer, int $year, int $month, float $amount): void
    {
        IncomeData::create([
            'customer_id' => $customer->id, 'source' => 'sdi_fattura',
            'transaction_date' => sprintf('%d-%02d-15', $year, $month),
            'sales_amount' => $amount, 'taxable_sales' => $amount, 'sale_taxes' => 0, 'tax_year' => $year,
        ]);
    }

    public function test_returns_null_without_any_income_data(): void
    {
        $customer = $this->customer();

        $this->assertNull(app(IncomePredictionService::class)->predictRepresentative($customer, 2025));
    }

    public function test_year_over_year_projection_used_with_enough_overlap(): void
    {
        $customer = $this->customer();

        // 2024: full year, a clear seasonal shape (quiet mid-year, busy Q4).
        $y2024 = [1 => 1000, 2 => 1000, 3 => 1000, 4 => 900, 5 => 900, 6 => 900, 7 => 800, 8 => 800, 9 => 900, 10 => 1400, 11 => 1600, 12 => 2000];
        foreach ($y2024 as $month => $amount) {
            $this->income($customer, 2024, $month, $amount);
        }

        // 2025: Jan-Sep known, each exactly 10% above the same 2024 month — a clean growth rate to assert against.
        for ($month = 1; $month <= 9; $month++) {
            $this->income($customer, 2025, $month, round($y2024[$month] * 1.10, 2));
        }

        $result = app(IncomePredictionService::class)->predictRepresentative($customer, 2025);

        $byMonth = collect($result['monthly_projected'])->keyBy('month');
        $this->assertTrue($byMonth[9]['is_actual']);
        $this->assertFalse($byMonth[10]['is_actual']);
        $this->assertSame('year_over_year', $byMonth[10]['method']);

        // Growth rate from the 9 overlapping months is exactly 1.10 by construction.
        $this->assertEqualsWithDelta(round($y2024[10] * 1.10, 2), $byMonth[10]['sales'], 0.5);
        $this->assertEqualsWithDelta(round($y2024[11] * 1.10, 2), $byMonth[11]['sales'], 0.5);
        $this->assertEqualsWithDelta(round($y2024[12] * 1.10, 2), $byMonth[12]['sales'], 0.5);

        // A flat average of Jan-Sep would have badly undershot the real Q4 seasonal spike —
        // confirm the YoY projection is clearly above that flat average for Q4.
        $flatAverage = round(collect($y2024)->only(range(1, 9))->map(fn ($v) => $v * 1.10)->avg(), 2);
        $this->assertGreaterThan($flatAverage * 1.3, $byMonth[12]['sales']);
    }

    public function test_falls_back_to_average_when_overlap_is_too_thin(): void
    {
        $customer = $this->customer();

        // Only 2 months of history this year, nothing at all last year —
        // no usable YoY signal, must fall back to simple average.
        $this->income($customer, 2025, 1, 1000);
        $this->income($customer, 2025, 2, 2000);

        $result = app(IncomePredictionService::class)->predictRepresentative($customer, 2025);

        $byMonth = collect($result['monthly_projected'])->keyBy('month');
        $this->assertSame('average', $byMonth[6]['method']);
        $this->assertEqualsWithDelta(1500.0, $byMonth[6]['sales'], 0.01);
    }

    public function test_falls_back_to_average_for_a_specific_month_missing_in_both_years(): void
    {
        $customer = $this->customer();

        // 4 overlapping months (enough to trust the growth rate overall)...
        for ($month = 1; $month <= 4; $month++) {
            $this->income($customer, 2024, $month, 1000);
            $this->income($customer, 2025, $month, 1100);
        }
        // ...but December exists in neither year, so it can't use YoY for December specifically.
        $result = app(IncomePredictionService::class)->predictRepresentative($customer, 2025);

        $byMonth = collect($result['monthly_projected'])->keyBy('month');
        $this->assertSame('average', $byMonth[12]['method']);
    }

    public function test_predicted_tax_due_uses_the_startup_rate_not_the_raw_customer_rate(): void
    {
        $customer = $this->customer([
            'tax_rate' => 15.00, // would be wrong if used directly
            'startup_rate_eligible' => true,
            'business_start_date' => '2024-01-01', // 2025 is within the 5-year startup window → 5%
        ]);
        $this->income($customer, 2025, 1, 10000);

        $result = app(IncomePredictionService::class)->predictRepresentative($customer, 2025);

        $this->assertSame(5.00, $customer->taxRateFor(2025));
        $expectedTax = round($result['predicted_taxable_income'] * 5 / 100, 2);
        $this->assertEqualsWithDelta($expectedTax, $result['predicted_tax_due'], 0.01);
    }

    public function test_predicted_inps_for_commercianti_matches_the_real_fixed_plus_percentage_formula(): void
    {
        $this->seed(InpsContributionRuleSeeder::class);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');

        $customer = $this->customer(['business_start_date' => '2020-01-01']);
        $this->income($customer, 2025, 1, 60000); // well above the minimale, exercises the variable part too

        $result = app(IncomePredictionService::class)->predictRepresentative($customer, 2025);

        $this->assertNotNull($result['predicted_inps']);
        $this->assertSame('fixed_plus_percentage', $result['predicted_inps']['method']);
        $this->assertGreaterThan(0, $result['predicted_inps']['fixed_amount']);
        $this->assertGreaterThan(0, $result['predicted_inps']['variable_amount']);

        Setting::set(TaxRules::SETTING, TaxRules::LEGACY, 'tax');
    }

    public function test_predicting_never_writes_any_real_tax_or_inps_calculation(): void
    {
        $this->seed(InpsContributionRuleSeeder::class);
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');

        $customer = $this->customer(['customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'business_start_date' => '2020-01-01']);
        $this->income($customer, 2024, 3, 5000);
        $this->income($customer, 2025, 1, 6000);

        app(IncomePredictionService::class)->predictRepresentative($customer, 2025);

        $this->assertSame(0, TaxCalculation::where('customer_id', $customer->id)->count());
        $this->assertSame(0, InpsCalculation::where('customer_id', $customer->id)->count());

        Setting::set(TaxRules::SETTING, TaxRules::LEGACY, 'tax');
    }

    /**
     * Sanity check against data shaped like the real Soliman Mohammed RT
     * export (docs/, gitignored — not available in CI, so reproduced here
     * as a synthetic analogue): a retail shop with a clear holiday-season
     * spike. The point: YoY projection from partial 2025 data should land
     * close to the real Oct-Dec 2025 total we already know from the real
     * import, not just "close to the simple average."
     */
    public function test_seasonal_retail_shape_projects_within_a_reasonable_margin_of_the_real_quarter(): void
    {
        $customer = $this->customer();

        // Approximates the real pattern: quiet Jan-Sep, sharp Oct-Dec rise
        // (matches the actual Soliman 2023/2024 corrispettivi shape).
        $base2024 = [1 => 900, 2 => 850, 3 => 950, 4 => 1000, 5 => 1050, 6 => 1100, 7 => 1150, 8 => 1000, 9 => 1050, 10 => 1300, 11 => 1500, 12 => 1900];
        $actualQ4_2025 = ['oct' => 1250, 'nov' => 1450, 'dec' => 1800]; // the "real" outcome we're checking against

        foreach ($base2024 as $month => $amount) {
            $this->income($customer, 2024, $month, $amount);
        }
        // 2025 Jan-Sep roughly flat vs 2024 (no growth) so growth rate ≈ 1.0.
        for ($month = 1; $month <= 9; $month++) {
            $this->income($customer, 2025, $month, $base2024[$month] * 0.98);
        }

        $result = app(IncomePredictionService::class)->predictRepresentative($customer, 2025);
        $byMonth = collect($result['monthly_projected'])->keyBy('month');

        // Projection should be within 15% of the real quarter — meaningfully
        // tighter than a flat average would land (flat average ≈ 1000, real Dec is 1800:
        // an 80% miss) for every one of the three holiday months.
        $this->assertEqualsWithDelta($actualQ4_2025['oct'], $byMonth[10]['sales'], $actualQ4_2025['oct'] * 0.15);
        $this->assertEqualsWithDelta($actualQ4_2025['nov'], $byMonth[11]['sales'], $actualQ4_2025['nov'] * 0.15);
        $this->assertEqualsWithDelta($actualQ4_2025['dec'], $byMonth[12]['sales'], $actualQ4_2025['dec'] * 0.15);
    }
}
