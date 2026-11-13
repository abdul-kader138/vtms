<?php

namespace Tests\Feature;

use App\Enums\TaxPaymentType;
use App\Models\Customer;
use App\Models\FatturaTraPrivatiPayment;
use App\Models\IncomeData;
use App\Models\InpsPayment;
use App\Models\TaxPayment;
use App\Models\User;
use App\Notifications\Tax\PaymentDueReminder;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaxCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->customer = Customer::create(['name' => 'Cmd Customer', 'customer_type' => 'artigiani', 'coefficient_of_profitability' => 67, 'tax_rate' => 5]);
        IncomeData::create([
            'customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-03-01',
            'sales_amount' => 20000, 'taxable_sales' => 20000, 'sale_taxes' => 0, 'tax_year' => 2025,
        ]);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mark_overdue_flips_only_pending_past_due_payments(): void
    {
        Carbon::setTestNow('2026-03-01');

        $balance = TaxPayment::where('payment_type', TaxPaymentType::Balance)->first(); // due 2026-02-16
        $firstAdvance = TaxPayment::where('payment_type', TaxPaymentType::FirstAdvance)->first(); // due 2026-06-30
        $paidInps = InpsPayment::where('installment_number', 1)->first(); // due 2025-05-16
        $paidInps->update(['status' => 'paid', 'paid_amount' => $paidInps->amount]);

        $this->artisan('tax:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $balance->fresh()->status->value);
        $this->assertSame('pending', $firstAdvance->fresh()->status->value);
        $this->assertSame('paid', $paidInps->fresh()->status->value);
        $this->assertSame('overdue', InpsPayment::where('installment_number', 2)->first()->status->value);
    }

    public function test_reminders_go_to_verified_linked_users_for_payments_due_in_n_days(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-02-09'); // 7 days before the 2026-02-16 balance / INPS 4th installment

        $linked = User::factory()->create(['first_name' => 'A', 'last_name' => 'B', 'customer_id' => $this->customer->id]);
        $unverified = User::factory()->unverified()->create(['first_name' => 'C', 'last_name' => 'D', 'customer_id' => $this->customer->id]);
        $unrelated = User::factory()->create(['first_name' => 'E', 'last_name' => 'F']);

        $this->artisan('tax:send-reminders')->assertSuccessful();

        Notification::assertSentTo($linked, PaymentDueReminder::class, fn (PaymentDueReminder $n) => $n->type === 'tax');
        Notification::assertSentTo($linked, PaymentDueReminder::class, fn (PaymentDueReminder $n) => $n->type === 'inps');
        Notification::assertNotSentTo($unverified, PaymentDueReminder::class);
        Notification::assertNotSentTo($unrelated, PaymentDueReminder::class);
    }

    public function test_fully_paid_payments_get_no_reminder(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-02-09');

        $linked = User::factory()->create(['first_name' => 'A', 'last_name' => 'B', 'customer_id' => $this->customer->id]);
        TaxPayment::query()->update(['status' => 'paid', 'paid_amount' => \DB::raw('amount')]);
        InpsPayment::query()->update(['status' => 'paid', 'paid_amount' => \DB::raw('amount')]);
        FatturaTraPrivatiPayment::query()->update(['status' => 'paid', 'paid_amount' => \DB::raw('amount')]);

        $this->artisan('tax:send-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($linked);
    }

    public function test_reminder_mail_renders(): void
    {
        $user = User::factory()->create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'customer_id' => $this->customer->id]);
        $payment = TaxPayment::where('payment_type', TaxPaymentType::Balance)->first();

        $mail = (new PaymentDueReminder('tax', $payment))->toMail($user);

        $this->assertStringContainsString('16/02/2026', $mail->subject);
        $this->assertSame(url('/app/tax'), $mail->actionUrl);
    }
}
