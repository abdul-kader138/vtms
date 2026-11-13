<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DirittoAnnualeBracket;
use App\Models\InpsRateSlab;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use App\Models\User;
use App\Services\Tax\TaxOverviewService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DirittoAnnualeBracketSeeder;
use Database\Seeders\InpsRateSlabSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_year_inps_slabs_are_carried_forward_and_flagged(): void
    {
        $this->seed(InpsRateSlabSeeder::class);
        $year = now()->year;

        foreach (['artigiani', 'commercianti', 'gestione_separata'] as $type) {
            $this->assertTrue(InpsRateSlab::where('slab_year', $year)->where('customer_type', $type)->exists(), "no {$year} slabs for {$type}");
        }
        $this->assertStringContainsString('CARRIED FORWARD', InpsRateSlab::where('slab_year', $year)->first()->description);
    }

    public function test_carry_forward_never_overwrites_real_rows(): void
    {
        $year = now()->year;
        InpsRateSlab::create(['slab_year' => $year, 'customer_type' => 'artigiani', 'income_from' => 1, 'income_to' => null, 'inps_rate' => 26.07, 'description' => 'real', 'is_active' => true]);

        $this->seed(InpsRateSlabSeeder::class);

        $this->assertSame(1, InpsRateSlab::where('slab_year', $year)->where('customer_type', 'artigiani')->count());
        $this->assertSame('real', InpsRateSlab::where('slab_year', $year)->where('customer_type', 'artigiani')->value('description'));
    }

    public function test_diritto_brackets_cover_every_year_and_keep_admin_edits(): void
    {
        $this->seed(DirittoAnnualeBracketSeeder::class);
        DirittoAnnualeBracket::where('bracket_year', 2025)->where('revenue_from', 0)->update(['amount' => 61.00]);
        $this->seed(DirittoAnnualeBracketSeeder::class);

        for ($y = DirittoAnnualeBracketSeeder::FIRST_YEAR; $y <= now()->year; $y++) {
            $this->assertSame(2, DirittoAnnualeBracket::where('bracket_year', $y)->count());
        }
        $this->assertSame('61.00', DirittoAnnualeBracket::where('bracket_year', 2025)->where('revenue_from', 0)->value('amount'));
    }

    public function test_full_seed_creates_working_demo_data_and_is_rerunnable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, Customer::where('vat_no', 'like', 'DEMO%')->count());
        $previous = now()->year - 1;

        $ferri = Customer::where('company', 'Bottega Ferri')->firstOrFail();
        $calc = TaxCalculation::where('customer_id', $ferri->id)->where('tax_year', $previous)->firstOrFail();
        // Historical year's two advances (375.20 each) were subtracted.
        $this->assertSame('750.40', $calc->advance_payments_made);

        // Studio Marino started in the previous year → no balance payment.
        $marino = Customer::where('company', 'Studio Marino')->firstOrFail();
        $this->assertFalse(TaxPayment::where('customer_id', $marino->id)->where('tax_year', $previous)->where('payment_type', 'balance')->exists());

        $user = User::where('email', 'demo.ferri@example.com')->firstOrFail();
        $this->assertSame($ferri->id, $user->customer_id);
        $this->assertFalse($user->isStaff());

        $overview = app(TaxOverviewService::class)->build($ferri, $previous);
        $this->assertNotNull($overview['inps']);
        $this->assertNotNull($overview['inail']);
        $this->assertTrue($overview['payments']['diritto_annuale']->isNotEmpty());
        $this->assertGreaterThan(0, $overview['summary']['total_paid']);

        $conti = Customer::where('company', 'Emporio Conti')->firstOrFail();
        if (now()->month > 2) {
            $this->assertNotEmpty(app(TaxOverviewService::class)->build($conti, now()->year)['warnings']);
        }
    }
}
