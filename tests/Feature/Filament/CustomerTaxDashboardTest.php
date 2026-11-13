<?php

namespace Tests\Feature\Filament;

use App\Enums\TaxPaymentType;
use App\Filament\Pages\CustomerTaxDashboard;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerTaxDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();

        $this->customer = Customer::create([
            'name' => 'Dashboard Test Artigiano',
            'customer_type' => 'artigiani',
            'coefficient_of_profitability' => 65.00,
            'tax_rate' => 5.00,
        ]);

        IncomeData::create([
            'customer_id' => $this->customer->id,
            'source' => 'sdi_fattura',
            'transaction_date' => '2025-03-01',
            'sales_amount' => 18401.67,
            'taxable_sales' => 18401.67,
            'sale_taxes' => 0,
            'tax_year' => 2025,
        ]);
    }

    public function test_dashboard_loads_with_no_calculation_yet(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->assertOk();
    }

    public function test_recalculate_action_creates_calculations(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('recalculate')
            ->assertOk();

        $this->assertDatabaseHas('tax_calculations', [
            'customer_id' => $this->customer->id,
            'tax_year' => 2025,
            'taxable_income' => 11961.09,
        ]);
    }

    public function test_mark_paid_action_is_reflected_immediately_without_reload(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('recalculate');

        $payment = TaxPayment::where('customer_id', $this->customer->id)
            ->where('payment_type', TaxPaymentType::Balance)
            ->firstOrFail();

        $markPaidButton = "mountAction('markPaid', { type: 'tax', id: {$payment->id} })";

        $component->assertSeeHtml($markPaidButton)
            ->callAction('markPaid', data: [
                'status' => 'paid',
                'paid_amount' => (string) $payment->amount,
                'paid_date' => now()->toDateString(),
            ], arguments: ['type' => 'tax', 'id' => $payment->id])
            ->assertOk()
            // Same render pass, no separate page load — payments are
            // re-queried fresh on every render, so the just-mounted
            // component's own HTML must already reflect the new status
            // (a paid row no longer offers "Mark paid").
            ->assertDontSeeHtml($markPaidButton);

        $this->assertDatabaseHas('tax_payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'paid_amount' => $payment->amount,
        ]);
    }

    public function test_recalculate_preserves_already_recorded_payment_tracking_fields(): void
    {
        $this->actingAs($this->admin);

        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $payment = TaxPayment::where('customer_id', $this->customer->id)
            ->where('payment_type', TaxPaymentType::Balance)
            ->firstOrFail();

        $payment->update([
            'status' => 'paid',
            'paid_amount' => $payment->amount,
            'paid_date' => '2026-01-15',
            'uploaded_pdf' => 'tax-receipts/already-uploaded.pdf',
        ]);

        // Re-running the calculation (e.g. because income_data changed)
        // must never silently erase that a human already marked this paid.
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $payment->refresh();

        $this->assertSame('paid', $payment->status->value);
        $this->assertEquals($payment->amount, $payment->paid_amount);
        $this->assertSame('2026-01-15', $payment->paid_date->toDateString());
        $this->assertSame('tax-receipts/already-uploaded.pdf', $payment->uploaded_pdf);
    }

    public function test_receipt_download_is_private_and_requires_a_signed_staff_request(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);

        $payment = TaxPayment::where('customer_id', $this->customer->id)
            ->where('payment_type', TaxPaymentType::Balance)
            ->firstOrFail();

        $path = 'tax-receipts/receipt.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake receipt');
        $payment->update(['uploaded_pdf' => $path]);

        $this->assertFalse(Storage::disk('public')->exists($path));

        $signedUrl = URL::temporarySignedRoute(
            'tax.payments.receipt.download',
            now()->addMinutes(30),
            ['customer' => $this->customer->id, 'type' => 'tax', 'id' => $payment->id],
        );

        // No session at all: redirected to login (redirectGuestsTo), not served.
        $this->get($signedUrl)->assertRedirect(route('filament.admin.auth.login'));

        // Authenticated staff + valid signature: served.
        $this->actingAs($this->admin)->get($signedUrl)->assertOk();

        // Authenticated staff but an unsigned/tampered URL: rejected (403).
        $unsignedUrl = route('tax.payments.receipt.download', ['customer' => $this->customer->id, 'type' => 'tax', 'id' => $payment->id]);
        $this->actingAs($this->admin)->get($unsignedUrl)->assertForbidden();
    }
}
