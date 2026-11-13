<?php

namespace Tests\Unit\Services\Tax;

use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\IncomeDataRelationManager;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\Setting;
use App\Models\TaxCalculation;
use App\Models\User;
use App\Services\Tax\IncomeCollectionService;
use App\Services\Tax\IncomePredictionService;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Services\Tax\TaxOverviewService;
use App\Support\Tax\TaxRules;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CashBasisTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);
        $this->customer = Customer::create(['name' => 'Cash', 'customer_type' => 'gestione_separata', 'coefficient_of_profitability' => 78, 'tax_rate' => 15]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $date, float $amount, array $extra = []): IncomeData
    {
        return IncomeData::create($extra + [
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => $date,
            'sales_amount' => $amount, 'taxable_sales' => $amount, 'sale_taxes' => 0, 'tax_year' => (int) substr($date, 0, 4),
        ]);
    }

    private function law(): void
    {
        Setting::set(TaxRules::SETTING, TaxRules::LAW, 'tax');
    }

    private function totalSales(int $year): float
    {
        return (float) app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, $year)['tax']->total_sales;
    }

    public function test_rows_default_to_collected_on_their_date_unless_explicitly_open(): void
    {
        $collected = $this->invoice('2025-03-01', 100);
        $open = $this->invoice('2025-03-02', 100, ['collected_on' => null]);

        $this->assertSame('2025-03-01', $collected->collected_on->toDateString());
        $this->assertSame(2025, $collected->income_year);
        $this->assertNull($open->fresh()->collected_on);
        $this->assertNull($open->fresh()->income_year);
    }

    public function test_legacy_rules_count_the_invoice_year(): void
    {
        $this->invoice('2025-12-20', 1000, ['collected_on' => '2026-01-10']);
        $this->invoice('2025-11-02', 500, ['collected_on' => null]);

        $this->assertEquals(1500, $this->totalSales(2025));
    }

    public function test_law_rules_count_the_collection_year(): void
    {
        $this->law();
        $this->invoice('2025-05-01', 2000);
        $this->invoice('2025-12-20', 1000, ['collected_on' => '2026-01-10']);
        $this->invoice('2025-11-02', 500, ['collected_on' => null]);

        $this->assertEquals(2000, $this->totalSales(2025));
        $this->assertEquals(1000, $this->totalSales(2026));

        $prediction = app(IncomePredictionService::class)->predict($this->customer, 2026);
        $this->assertSame([1], collect($prediction['monthly_sales'])->pluck('month')->all(), 'placed in January, the month collected');

        $warnings = app(TaxOverviewService::class)->build($this->customer, 2025)['warnings'];
        $this->assertStringContainsString('1 invoices of 2025', collect($warnings)->pluck('message')->implode(' '));
    }

    public function test_changing_the_collection_date_recalculates_both_years_under_law_rules(): void
    {
        $this->law();
        $row = $this->invoice('2025-12-20', 1000);
        $this->invoice('2026-02-01', 400);
        $this->totalSales(2025);
        $this->totalSales(2026);

        $result = app(IncomeCollectionService::class)->setCollectedOn(collect([$row]), Carbon::parse('2026-01-10'));

        $this->assertCount(2, $result['recalculated']);
        $this->assertEquals(0, (float) TaxCalculation::where('tax_year', 2025)->value('total_sales'));
        $this->assertEquals(1400, (float) TaxCalculation::where('tax_year', 2026)->value('total_sales'));

        app(IncomeCollectionService::class)->setCollectedOn(collect([$row->fresh()]), null);
        $this->assertEquals(400, (float) TaxCalculation::where('tax_year', 2026)->value('total_sales'));
    }

    public function test_ledger_bulk_action_sets_the_collection_date(): void
    {
        $this->seed(ShieldSeeder::class);
        $this->actingAs(User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail());
        $a = $this->invoice('2025-12-20', 1000, ['collected_on' => null]);
        $b = $this->invoice('2025-12-21', 300, ['collected_on' => null]);

        Livewire::test(IncomeDataRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => EditCustomer::class])
            ->assertSee(__('Not collected'))
            ->callTableBulkAction('collected', [$a, $b], ['collected' => true, 'collected_on' => '2026-01-15'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(2026, $a->fresh()->income_year);
        $this->assertSame(2026, $b->fresh()->income_year);
    }
}
