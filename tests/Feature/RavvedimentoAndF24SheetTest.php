<?php

namespace Tests\Feature;

use App\Enums\TaxPaymentType;
use App\Filament\Pages\CustomerTaxDashboard;
use App\Filament\Pages\TaxSettings;
use App\Models\Customer;
use App\Models\DirittoAnnualePayment;
use App\Models\InpsCalculation;
use App\Models\InpsPayment;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\F24SheetService;
use App\Services\Tax\RavvedimentoService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RavvedimentoAndF24SheetTest extends TestCase
{
    use RefreshDatabase;

    private RavvedimentoService $r;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-30');
        $this->r = app(RavvedimentoService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function late(string $due, string $payOn, string $type = 'tax'): array
    {
        return $this->r->calculate($type, 1000, Carbon::parse($due), Carbon::parse($payOn));
    }

    public function test_sprint_within_14_days_is_charged_per_day(): void
    {
        $x = $this->late('2025-06-30', '2025-07-10');

        $this->assertSame(10, $x['days']);
        $this->assertEquals(8.33, $x['sanction']);  // 12.5% × 10/15 × 1/10
        $this->assertEquals(0.55, $x['interest']);  // 1000 × 2% × 10/365
        $this->assertSame(['1793', '8913'], [$x['interest_code'], $x['sanction_code']]);
    }

    public function test_reductions_by_how_late(): void
    {
        $this->assertEquals(12.50, $this->late('2025-06-30', '2025-07-25')['sanction']);  // 25 days: 12.5% × 1/10
        $this->assertEquals(13.89, $this->late('2025-06-30', '2025-09-28')['sanction']);  // 90 days: 12.5% × 1/9

        $year = $this->late('2025-06-30', '2026-03-30');                                // 273 days: 25% × 1/8
        $this->assertEquals(31.25, $year['sanction']);
        // 184 days of 2025 at 2% + 89 days of 2026 at 1.6%
        $this->assertEquals(13.98, $year['interest']);
    }

    public function test_deadlines_before_the_2024_reform_use_the_old_30_percent_base(): void
    {
        $this->assertEquals(15.00, $this->late('2024-06-30', '2024-07-20')['sanction']); // 15% × 1/10
    }

    public function test_inps_is_not_calculated_here(): void
    {
        $x = $this->late('2025-06-30', '2025-07-20', 'inps');

        $this->assertFalse($x['applicable']);
        $this->assertNotNull($x['reason']);
    }

    private function customerWithDeadline(): Customer
    {
        $c = Customer::create(['name' => 'Rossi', 'company' => 'Rossi Srl', 'vat_no' => '01234567890', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 15]);
        $tc = TaxCalculation::create(['customer_id' => $c->id, 'tax_year' => 2025, 'total_sales' => 10000, 'taxable_income' => 6700, 'coefficient_used' => 67, 'tax_rate_used' => 15, 'tax_due' => 1000, 'advance_payments_made' => 0, 'balance_payment' => 1000, 'next_year_advance_base' => 1000]);
        $ic = InpsCalculation::create(['customer_id' => $c->id, 'tax_year' => 2025, 'taxable_income' => 6700, 'inps_rate' => 24, 'inps_amount' => 700, 'discount_percentage' => 0, 'discount_amount' => 0, 'inps_amount_after_discount' => 700, 'calculation_method' => 'percentage']);
        TaxPayment::create(['customer_id' => $c->id, 'payment_type' => TaxPaymentType::Balance, 'tax_year' => 2025, 'amount' => 1000, 'due_date' => '2026-03-16', 'status' => 'pending', 'paid_amount' => 0, 'tax_calculation_id' => $tc->id]);
        TaxPayment::create(['customer_id' => $c->id, 'payment_type' => TaxPaymentType::FirstAdvance, 'tax_year' => 2026, 'amount' => 400, 'due_date' => '2026-03-16', 'status' => 'pending', 'paid_amount' => 100, 'tax_calculation_id' => $tc->id]);
        InpsPayment::create(['customer_id' => $c->id, 'payment_year' => 2025, 'installment_number' => 5, 'amount' => 700, 'due_date' => '2026-03-16', 'status' => 'pending', 'paid_amount' => 0, 'inps_calculation_id' => $ic->id]);
        DirittoAnnualePayment::create(['customer_id' => $c->id, 'payment_year' => 2026, 'amount' => 53, 'due_date' => '2026-03-16', 'status' => 'paid', 'paid_amount' => 53]);

        return $c;
    }

    public function test_f24_sheet_lists_open_lines_by_section_with_late_lines(): void
    {
        $c = $this->customerWithDeadline();

        $sheet = app(F24SheetService::class)->build($c, Carbon::parse('2026-03-16'), Carbon::parse('2026-03-30'));

        $this->assertSame(14, $sheet['late_days']);
        $erario = $sheet['sections']->firstWhere('name', 'Erario');
        $this->assertSame(['1792', '1793', '8913', '1790', '1793', '8913'], $erario['lines']->pluck('code')->all());
        $this->assertSame([2025, 2025, 2025, 2026, 2026, 2026], $erario['lines']->pluck('year')->all());
        $this->assertEquals(300, $erario['lines'][3]['amount'], 'only what is still open');

        $inps = $sheet['sections']->firstWhere('name', 'INPS');
        $this->assertSame('AP', $inps['lines'][0]['code']);
        $this->assertSame('01/2025 – 12/2025', $inps['lines'][0]['period']);
        $this->assertCount(1, $inps['lines'], 'no ravvedimento lines for INPS');
        $this->assertNull($sheet['sections']->firstWhere('name', 'IMU e altri tributi locali'), 'paid Diritto Annuale is left out');
        $this->assertNotEmpty($sheet['notes']);
    }

    public function test_pdf_download_is_signed_and_staff_only(): void
    {
        $this->seed(ShieldSeeder::class);
        $c = $this->customerWithDeadline();
        $url = CustomerTaxDashboard::f24SheetUrl($c->id, Carbon::parse('2026-03-16'), Carbon::parse('2026-03-30'));

        $admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
        $this->actingAs($admin)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($admin)->get(str_replace('pay_on=2026-03-30', 'pay_on=2026-03-31', $url))->assertForbidden();

        $customerUser = User::factory()->create(['customer_id' => $c->id]);
        $this->actingAs($customerUser)->get($url)->assertForbidden();
    }

    public function test_dashboard_late_cost_modal_shows_the_breakdown(): void
    {
        $this->seed(ShieldSeeder::class);
        $this->actingAs(User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail());
        $c = $this->customerWithDeadline();
        $balance = TaxPayment::where('customer_id', $c->id)->where('payment_type', TaxPaymentType::Balance)->first();

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $c->id])
            ->set('selectedYear', 2025)
            ->assertSeeHtml("mountAction('lateCost'")
            ->mountAction('lateCost', ['type' => 'tax', 'id' => $balance->id])
            ->assertSee('8913')
            ->assertSee('€ 1.000,00');
    }

    public function test_legal_interest_rates_are_editable_in_tax_settings(): void
    {
        $this->seed(ShieldSeeder::class);
        $this->actingAs(User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail());

        Livewire::test(TaxSettings::class)
            ->assertSet('data.legal_interest_rates.2025', '2')
            ->set('data.legal_interest_rates', ['2025' => '2', '2026' => '3'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3.0, RavvedimentoService::rates()[2026]);

        Livewire::test(TaxSettings::class)
            ->set('data.legal_interest_rates', ['26' => 'x'])
            ->call('save')
            ->assertHasErrors();
    }
}
