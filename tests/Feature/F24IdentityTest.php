<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DirittoAnnualePayment;
use App\Models\InpsCalculation;
use App\Models\InpsPayment;
use App\Services\Tax\F24SheetService;
use App\Support\Tax\F24Code;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class F24IdentityTest extends TestCase
{
    use RefreshDatabase;

    private function inps(Customer $c, int $installment): InpsPayment
    {
        $ic = InpsCalculation::firstOrCreate(['customer_id' => $c->id, 'tax_year' => 2025], ['taxable_income' => 1, 'inps_rate' => 1, 'inps_amount' => 1, 'discount_percentage' => 0, 'discount_amount' => 0, 'inps_amount_after_discount' => 1, 'calculation_method' => 'percentage']);

        return InpsPayment::create(['customer_id' => $c->id, 'inps_calculation_id' => $ic->id, 'payment_year' => 2025, 'installment_number' => $installment, 'amount' => 100, 'due_date' => '2026-06-30', 'status' => 'pending', 'paid_amount' => 0])->load('customer');
    }

    public function test_inps_codes_follow_the_inps_causali(): void
    {
        $art = Customer::create(['name' => 'A', 'customer_type' => 'artigiani']);
        $com = Customer::create(['name' => 'C', 'customer_type' => 'commercianti']);
        $gs = Customer::create(['name' => 'G', 'customer_type' => 'gestione_separata']);
        $gsPension = Customer::create(['name' => 'P', 'customer_type' => 'gestione_separata', 'gs_other_pension' => true]);

        $this->assertSame('AF', F24Code::for('inps', $this->inps($art, 1)));
        $this->assertSame('AP', F24Code::for('inps', $this->inps($art, 5)));
        $this->assertSame('CF', F24Code::for('inps', $this->inps($com, 2)));
        $this->assertSame('CP', F24Code::for('inps', $this->inps($com, 6)));
        $this->assertSame('PXX', F24Code::for('inps', $this->inps($gs, 1)));
        $this->assertSame('P10', F24Code::for('inps', $this->inps($gsPension, 1)));
    }

    public function test_sheet_carries_the_customer_identity_and_only_asks_for_what_is_missing(): void
    {
        $c = Customer::create(['name' => 'Rossi', 'customer_type' => 'artigiani', 'codice_fiscale' => 'RSSMRA80A01H501U', 'inps_office_code' => '3900', 'inps_code' => '12345678901234567', 'chamber_province' => 'FI']);
        $this->inps($c, 5);
        DirittoAnnualePayment::create(['customer_id' => $c->id, 'payment_year' => 2026, 'amount' => 53, 'due_date' => '2026-06-30', 'status' => 'pending', 'paid_amount' => 0]);

        $sheet = app(F24SheetService::class)->build($c, Carbon::parse('2026-06-30'), Carbon::parse('2026-06-30'));

        $inps = $sheet['sections']->firstWhere('name', 'INPS')['lines'][0];
        $this->assertSame(['3900', '12345678901234567', '01/2025 – 12/2025'], [$inps['office'], $inps['account'], $inps['period']]);
        $this->assertSame('FI', $sheet['sections']->firstWhere('name', 'IMU e altri tributi locali')['lines'][0]['office']);
        $this->assertSame([], $sheet['notes']);

        $html = view('pdf.tax.f24-sheet', $sheet)->render();
        $this->assertStringContainsString('RSSMRA80A01H501U', $html);
        $this->assertStringContainsString('12345678901234567', $html);
    }
}
