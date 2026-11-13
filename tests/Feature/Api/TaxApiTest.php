<?php

namespace Tests\Feature\Api;

use App\Enums\TaxPaymentType;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaxApiTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->customer = Customer::create(['name' => 'Portal Customer', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        $this->user = User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'customer_id' => $this->customer->id]);

        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 20000, 'taxable_sales' => 20000, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/v1/tax/years')->assertUnauthorized();
        $this->getJson('/api/v1/tax/2025')->assertUnauthorized();
    }

    public function test_user_without_a_linked_customer_gets_a_clear_404(): void
    {
        $user = User::factory()->create(['first_name' => 'No', 'last_name' => 'Customer']);

        $this->actingAs($user)->getJson('/api/v1/tax/2025')
            ->assertNotFound()
            ->assertJsonFragment(['message' => 'No customer record is linked to this account yet. Please contact your accountant.']);
    }

    public function test_years_lists_income_and_current_years(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/tax/years')
            ->assertOk()
            ->assertJsonPath('customer.name', 'Portal Customer')
            ->assertJsonPath('default_year', now()->year)
            ->assertJsonFragment(['years' => collect([now()->year, 2025])->unique()->sortDesc()->values()->all()]);
    }

    public function test_year_before_calculation_returns_live_estimate_and_prediction(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/tax/2025')
            ->assertOk()
            ->assertJsonPath('calculated', false)
            ->assertJsonPath('tax', null)
            ->assertJsonPath('estimate.taxable_income', 13400)
            ->assertJsonPath('prediction.predicted_total_sales', 240000)
            ->assertJsonPath('annual_report_url', null);
    }

    public function test_calculated_year_returns_figures_payments_and_report_link(): void
    {
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $response = $this->actingAs($this->user)->getJson('/api/v1/tax/2025')
            ->assertOk()
            ->assertJsonPath('calculated', true)
            ->assertJsonPath('tax.taxable_income', '13400.00')
            ->assertJsonPath('annual_report_url', route('api.v1.tax.annual-report', ['year' => 2025]));

        $types = collect($response->json('payments'))->pluck('type')->unique()->values()->all();
        $this->assertContains('tax', $types);
        $this->assertContains('inps', $types);
        $this->assertArrayNotHasKey('uploaded_pdf', $response->json('payments.0'));

        $this->actingAs($this->user)->get('/api/v1/tax/2025/annual-report')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_annual_report_404s_before_calculation(): void
    {
        $this->actingAs($this->user)->get('/api/v1/tax/2025/annual-report')->assertNotFound();
    }

    public function test_receipt_download_is_limited_to_the_users_own_customer(): void
    {
        Storage::fake('local');
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $payment = TaxPayment::where('customer_id', $this->customer->id)->where('payment_type', TaxPaymentType::Balance)->firstOrFail();
        Storage::disk('local')->put('tax-receipts/r.pdf', '%PDF-1.4 receipt');
        $payment->update(['uploaded_pdf' => 'tax-receipts/r.pdf']);

        $this->actingAs($this->user)->get("/api/v1/tax/payments/tax/{$payment->id}/receipt")->assertOk();

        $other = Customer::create(['name' => 'Someone Else', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        $intruder = User::factory()->create(['first_name' => 'Eve', 'last_name' => 'X', 'customer_id' => $other->id]);

        $this->actingAs($intruder)->get("/api/v1/tax/payments/tax/{$payment->id}/receipt")->assertNotFound();
        $this->actingAs($this->user)->get("/api/v1/tax/payments/unknown/{$payment->id}/receipt")->assertNotFound();
    }

    public function test_unverified_users_are_blocked(): void
    {
        $user = User::factory()->unverified()->create(['first_name' => 'U', 'last_name' => 'V', 'customer_id' => $this->customer->id]);

        $this->actingAs($user)->getJson('/api/v1/tax/2025')->assertForbidden();
    }

    public function test_customer_can_report_a_payment_with_a_receipt(): void
    {
        Storage::fake('local');
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
        $payment = TaxPayment::where('customer_id', $this->customer->id)->where('payment_type', TaxPaymentType::Balance)->firstOrFail();

        $response = $this->actingAs($this->user)->post("/api/v1/tax/payments/tax/{$payment->id}/report-paid", [
            'paid_date' => '2025-06-30',
            'paid_amount' => '100.50',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ]);

        $response->assertOk()->assertJsonPath('payment.reported_paid_amount', 100.5);
        $payment->refresh();
        $this->assertNotNull($payment->reported_paid_at);
        $this->assertSame('2025-06-30', $payment->reported_paid_date->toDateString());
        Storage::disk('local')->assertExists($payment->uploaded_pdf);
    }

    public function test_f24_download_only_accepts_an_open_deadline_of_the_users_customer(): void
    {
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
        $payment = TaxPayment::where('customer_id', $this->customer->id)->where('payment_type', TaxPaymentType::Balance)->firstOrFail();

        $this->actingAs($this->user)->get('/api/v1/tax/f24/'.$payment->due_date->toDateString())
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->user)->get('/api/v1/tax/f24/2099-12-31')->assertNotFound();

        $other = Customer::create(['name' => 'Someone Else', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        $intruder = User::factory()->create(['customer_id' => $other->id]);
        $this->actingAs($intruder)->get('/api/v1/tax/f24/'.$payment->due_date->toDateString())->assertNotFound();
    }

    public function test_customer_calendar_feed_and_reset_replace_the_old_secret(): void
    {
        $calendar = $this->actingAs($this->user)->getJson('/api/v1/tax/calendar')->assertOk()->json();
        $oldUrl = $calendar['url'];

        $this->get($oldUrl)->assertOk()->assertHeader('content-type', 'text/calendar; charset=utf-8');
        $reset = $this->actingAs($this->user)->postJson('/api/v1/tax/calendar/reset')->assertOk()->json();

        $this->assertNotSame($oldUrl, $reset['url']);
        $this->get($oldUrl)->assertNotFound();
        $this->get($reset['url'])->assertOk()->assertSee('BEGIN:VCALENDAR');
    }

    public function test_payments_carry_f24_codes_and_rule_details(): void
    {
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $payments = collect($this->actingAs($this->user)->getJson('/api/v1/tax/2025')
            ->assertOk()
            ->assertJsonPath('tax.rules', 'legacy')
            ->assertJsonPath('tax.gross_income', 13400)
            ->assertJsonPath('tax_rate_for_year', 5)
            ->json('payments'));

        $balance = $payments->firstWhere('description', 'Flat tax (imposta sostitutiva) — balance');
        $this->assertSame('1792', $balance['f24_code']);
        $this->assertSame('Erario', $balance['f24_section']);
        $this->assertSame('AF', $payments->firstWhere('type', 'inps')['f24_code']); // Artigiani
    }

    public function test_italian_users_get_italian_messages(): void
    {
        $this->user->forceFill(['locale' => 'it'])->save();
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $descriptions = collect($this->actingAs($this->user)->getJson('/api/v1/tax/2025')->json('payments'))->pluck('description');
        $this->assertContains('Imposta sostitutiva — saldo', $descriptions->all());

        $unlinked = User::factory()->create(['first_name' => 'N', 'last_name' => 'L', 'locale' => 'it']);
        $this->actingAs($unlinked)->getJson('/api/v1/tax/2025')
            ->assertNotFound()
            ->assertJsonFragment(['message' => 'A questo account non è ancora collegata alcuna posizione cliente. Contatta il tuo commercialista.']);
    }

    public function test_italian_shell_embeds_the_translation_catalog(): void
    {
        $this->withSession(['locale' => 'it'])->get('/app/tax')->assertOk()->assertSee('Scadenzario pagamenti', false);
        $this->withSession(['locale' => 'bn'])->get('/login')->assertOk(); // no bn catalog — must not crash
    }

    public function test_customer_dashboard_shell_renders(): void
    {
        $this->get('/app/tax')->assertOk()->assertSee('customer-tax-root', false);
    }
}
