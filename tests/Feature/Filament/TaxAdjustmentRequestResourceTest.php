<?php

namespace Tests\Feature\Filament;

use App\Enums\TaxAdjustmentRequestStatus;
use App\Filament\Resources\TaxAdjustmentRequestResource\Pages\ListTaxAdjustmentRequests;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\TaxAdjustmentRequest;
use App\Models\User;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaxAdjustmentRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private array $preview;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->customer = Customer::create([
            'name' => 'Queue Test Customer',
            'customer_type' => 'gestione_separata',
            'coefficient_of_profitability' => 78.00,
            'tax_rate' => 5.00,
        ]);

        IncomeData::create([
            'customer_id' => $this->customer->id,
            'source' => 'sdi_fattura',
            'transaction_date' => '2025-03-01',
            'sales_amount' => 10000.00,
            'taxable_sales' => 10000.00,
            'sale_taxes' => 0,
            'tax_year' => 2025,
        ]);

        $this->preview = app(TaxCalculationOrchestrator::class)->preview($this->customer, 2025);
    }

    private function createPendingRequest(User $requester): TaxAdjustmentRequest
    {
        return TaxAdjustmentRequest::create([
            'customer_id' => $this->customer->id,
            'tax_year' => 2025,
            'payload' => [
                'inps' => array_merge($this->preview['inps'], [
                    'inps_amount_after_discount' => (float) $this->preview['inps']['inps_amount_after_discount'] + 10,
                    'adjustment_reason' => 'Rounding correction.',
                ]),
            ],
            'status' => TaxAdjustmentRequestStatus::Pending,
            'requested_by' => $requester->id,
            'requested_at' => now(),
        ]);
    }

    public function test_approver_can_approve_a_pending_request(): void
    {
        $requester = User::factory()->create();
        $approver = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
        $request = $this->createPendingRequest($requester);

        $this->actingAs($approver);

        Livewire::test(ListTaxAdjustmentRequests::class)
            ->callTableAction('approve', $request)
            ->assertOk();

        $this->assertDatabaseHas('tax_adjustment_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by' => $approver->id,
        ]);
        $this->assertDatabaseHas('inps_calculations', [
            'customer_id' => $this->customer->id,
            'tax_year' => 2025,
            'is_manually_adjusted' => true,
            'adjusted_by' => $approver->id,
        ]);
    }

    public function test_approver_can_reject_a_pending_request(): void
    {
        $requester = User::factory()->create();
        $approver = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
        $request = $this->createPendingRequest($requester);

        $this->actingAs($approver);

        Livewire::test(ListTaxAdjustmentRequests::class)
            ->callTableAction('reject', $request, data: ['review_note' => 'Not justified.'])
            ->assertOk();

        $this->assertDatabaseHas('tax_adjustment_requests', [
            'id' => $request->id,
            'status' => 'rejected',
            'reviewed_by' => $approver->id,
            'review_note' => 'Not justified.',
        ]);
        $this->assertDatabaseMissing('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
    }

    public function test_a_user_without_the_finalize_permission_cannot_approve(): void
    {
        $requester = User::factory()->create();
        $staff = User::factory()->create();
        $request = $this->createPendingRequest($requester);

        $this->actingAs($staff);

        try {
            Livewire::test(ListTaxAdjustmentRequests::class)->callTableAction('approve', $request);
        } catch (\Throwable) {
            // Filament refuses to call a hidden action — either way, nothing should change below.
        }

        $this->assertDatabaseHas('tax_adjustment_requests', ['id' => $request->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
    }
}
