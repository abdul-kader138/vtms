<?php

namespace Tests\Feature\Filament;

use App\Enums\TaxPaymentType;
use App\Filament\Pages\CustomerTaxDashboard;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\InpsCalculationService;
use App\Services\Tax\PaymentScheduleService;
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

    public function test_employee_cost_entered_in_the_review_modal_is_persisted_and_survives_a_later_recalculation(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.tax.employee_cost', 25000.00)
            ->callMountedAction()
            ->assertOk();

        $this->assertDatabaseHas('tax_calculations', [
            'customer_id' => $this->customer->id, 'tax_year' => 2025, 'employee_cost' => 25000.00,
        ]);

        // Recalculating again without re-entering it must not wipe it out.
        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('recalculate')
            ->assertOk();

        $this->assertDatabaseHas('tax_calculations', [
            'customer_id' => $this->customer->id, 'tax_year' => 2025, 'employee_cost' => 25000.00,
        ]);
    }

    public function test_recalculate_modal_auto_populates_the_inps_net_when_the_discount_percentage_changes(): void
    {
        $this->actingAs($this->admin);

        $preview = app(TaxCalculationOrchestrator::class)->preview($this->customer, 2025);
        $inpsAmount = (float) $preview['inps']['inps_amount'];

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.inps.discount_percentage', 10)
            ->assertSet('mountedActionsData.0.inps.discount_amount', round($inpsAmount * 0.10, 2))
            ->assertSet('mountedActionsData.0.inps.inps_amount_after_discount', round($inpsAmount - round($inpsAmount * 0.10, 2), 2));
    }

    public function test_recalculate_modal_shows_a_provisional_payment_schedule(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->assertSeeHtml('Provisional payment schedule')
            ->assertSeeHtml('Tax — balance');
    }

    public function test_editing_one_inps_installment_rescales_the_others_in_the_same_group(): void
    {
        $this->actingAs($this->admin);

        $preview = app(TaxCalculationOrchestrator::class)->preview($this->customer, 2025);
        $schedule = app(PaymentScheduleService::class)->previewForYear($this->customer, 2025, $preview);
        $groupTotal = collect($schedule['inps'])->sum('amount');
        $installment2 = (float) $schedule['inps']['2']['amount'];
        $installment3 = (float) $schedule['inps']['3']['amount'];
        $installment4 = (float) $schedule['inps']['4']['amount'];
        $othersTotal = $installment2 + $installment3 + $installment4;

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.schedule.inps.1.amount', 0)
            ->assertSet('mountedActionsData.0.schedule.inps.2.amount', round($installment2 / $othersTotal * $groupTotal, 2))
            ->assertSet('mountedActionsData.0.schedule.inps.3.amount', round($installment3 / $othersTotal * $groupTotal, 2));
    }

    public function test_changing_the_inps_discount_amount_resplits_the_inps_installments_to_the_new_net(): void
    {
        $this->actingAs($this->admin);

        $preview = app(TaxCalculationOrchestrator::class)->preview($this->customer, 2025);
        $preview['inps']['discount_amount'] = 100.00;
        $preview['inps']['discount_percentage'] = round(100.00 / (float) $preview['inps']['inps_amount'] * 100, 2);
        $preview['inps']['inps_amount_after_discount'] = round((float) $preview['inps']['inps_amount'] - 100.00, 2);
        $expectedSchedule = app(PaymentScheduleService::class)->previewForYear($this->customer, 2025, $preview)['inps'];

        $component = Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.inps.discount_amount', 100.00)
            ->assertSet('mountedActionsData.0.inps.inps_amount_after_discount', $preview['inps']['inps_amount_after_discount']);

        foreach ($expectedSchedule as $key => $row) {
            $component->assertSet("mountedActionsData.0.schedule.inps.{$key}.amount", $row['amount']);
        }

        // A later hand-edit must rebalance against the new net, not the one the modal opened with.
        $component->set('mountedActionsData.0.schedule.inps.1.amount', 0);
        $installmentsTotal = collect(array_keys($expectedSchedule))
            ->sum(fn ($key) => (float) $component->get("mountedActionsData.0.schedule.inps.{$key}.amount"));

        $this->assertEqualsWithDelta($preview['inps']['inps_amount_after_discount'], $installmentsTotal, 0.001);
    }

    public function test_changing_tax_due_and_balance_payment_resplits_the_tax_installments(): void
    {
        $this->actingAs($this->admin);

        $preview = app(TaxCalculationOrchestrator::class)->preview($this->customer, 2025);
        $preview['tax']['tax_due'] = 2000.00;
        $preview['tax']['balance_payment'] = 500.00;
        $expectedSchedule = app(PaymentScheduleService::class)->previewForYear($this->customer, 2025, $preview)['tax'];

        $component = Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.tax.tax_due', 2000.00)
            ->set('mountedActionsData.0.tax.balance_payment', 500.00);

        $this->assertNotEmpty($expectedSchedule);
        $this->assertSame(array_keys($expectedSchedule), array_keys($component->get('mountedActionsData.0.schedule.tax')));
        foreach ($expectedSchedule as $key => $row) {
            $component->assertSet("mountedActionsData.0.schedule.tax.{$key}.amount", $row['amount']);
        }
    }

    public function test_changing_taxable_income_resyncs_the_inps_figures_to_the_new_slab(): void
    {
        $this->actingAs($this->admin);

        $newIncome = 5000.00;
        $expected = app(InpsCalculationService::class)->expectedForIncome($this->customer, 2025, $newIncome);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.tax.taxable_income', $newIncome)
            ->assertSet('mountedActionsData.0.inps.inps_amount', $expected['inps_amount'])
            ->assertSet('mountedActionsData.0.inps.inps_amount_after_discount', $expected['inps_amount_after_discount']);
    }

    public function test_hand_editing_inps_amount_after_a_taxable_income_change_shows_a_mismatch_warning_and_blocks_saving(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.tax.taxable_income', 5000.00)
            ->set('mountedActionsData.0.inps.inps_amount', 999999.00)
            ->assertSeeHtml('implies an INPS amount')
            ->set('mountedActionsData.0.tax.adjustment_reason', 'Income corrected.')
            ->set('mountedActionsData.0.inps.adjustment_reason', 'Testing mismatch.')
            ->callMountedAction()
            ->assertOk();

        $this->assertDatabaseMissing('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025, 'inps_amount' => 999999.00]);
    }

    public function test_confirming_the_slab_mismatch_allows_it_to_be_saved(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.tax.taxable_income', 5000.00)
            ->set('mountedActionsData.0.inps.inps_amount', 999999.00)
            ->set('mountedActionsData.0.tax.adjustment_reason', 'Income corrected.')
            ->set('mountedActionsData.0.inps.adjustment_reason', 'Testing mismatch.')
            ->set('mountedActionsData.0.confirm_slab_mismatch', true)
            ->callMountedAction()
            ->assertOk();

        $this->assertDatabaseHas('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025, 'inps_amount' => 999999.00]);
    }

    public function test_submitting_an_edited_installment_persists_it_and_requires_a_reason(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.schedule.inps.1.amount', 100.00)
            ->callMountedAction()
            ->assertOk();

        // No reason was given, so the whole commit is refused — nothing persisted.
        $this->assertDatabaseMissing('inps_payments', ['customer_id' => $this->customer->id, 'installment_number' => 1, 'amount' => 100.00]);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->mountAction('recalculate')
            ->set('mountedActionsData.0.schedule.inps.1.amount', 100.00)
            ->set('mountedActionsData.0.inps.adjustment_reason', 'Settled installment 1 separately.')
            ->callMountedAction()
            ->assertOk();

        $this->assertDatabaseHas('inps_payments', ['customer_id' => $this->customer->id, 'installment_number' => 1, 'amount' => 100.00]);
        $this->assertDatabaseHas('inps_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025, 'is_manually_adjusted' => true]);
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

    public function test_recalculate_queues_an_adjustment_request_when_staff_lacks_finalize_permission(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff);

        $preview = app(TaxCalculationOrchestrator::class)->preview($this->customer, 2025);
        $overrideAmount = (float) $preview['inps']['inps_amount_after_discount'] + 10;

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('recalculate', data: [
                'tax' => $preview['tax'],
                'inps' => array_merge($preview['inps'], [
                    'inps_amount_after_discount' => $overrideAmount,
                    'adjustment_reason' => 'Testing the review queue.',
                ]),
                'inail' => $preview['inail'] ?? [],
                'fattura_tra_privati' => $preview['fattura_tra_privati'] ?? [],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
        $this->assertDatabaseHas('tax_adjustment_requests', [
            'customer_id' => $this->customer->id,
            'tax_year' => 2025,
            'status' => 'pending',
            'requested_by' => $staff->id,
        ]);
    }

    public function test_locking_the_year_blocks_recalculation_and_unlocking_restores_it(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('lockYear')
            ->assertOk();

        $this->assertDatabaseHas('tax_year_locks', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('recalculate')
            ->assertOk();

        $this->assertDatabaseMissing('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('unlockYear')
            ->assertOk();

        $this->assertDatabaseMissing('tax_year_locks', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);

        Livewire::test(CustomerTaxDashboard::class, ['customer' => $this->customer->id])
            ->set('selectedYear', 2025)
            ->callAction('recalculate')
            ->assertOk();

        $this->assertDatabaseHas('tax_calculations', ['customer_id' => $this->customer->id, 'tax_year' => 2025]);
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
