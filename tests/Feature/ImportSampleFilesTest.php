<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Tax\Import\IncomeDataImportService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every downloadable sample must import cleanly through the real pipeline. */
class ImportSampleFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
        $this->admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail();
    }

    private function download(string $format, string $ext, ?Customer $customer = null): string
    {
        $response = $this->actingAs($this->admin)->get(route('tax.import-sample', array_filter(['format' => $format, 'ext' => $ext, 'customer' => $customer?->id])));
        $response->assertOk()->assertDownload();

        $path = tempnam(sys_get_temp_dir(), 'sample');
        file_put_contents($path, $response->streamedContent());

        return $path;
    }

    public static function samples(): array
    {
        return [
            'invoices csv' => ['invoices', 'csv', 'sdi_csv', 3, 1, 0],
            'invoices xlsx' => ['invoices', 'xlsx', 'sdi_csv', 3, 1, 0],
            'cash register csv' => ['cash-register', 'csv', 'device_csv', 2, 0, 1],
            'cash register xlsx' => ['cash-register', 'xlsx', 'device_csv', 2, 0, 1],
            'e-invoice xml' => ['e-invoice', 'xml', 'fatturapa_xml', 1, 0, 0],
        ];
    }

    #[DataProvider('samples')]
    public function test_sample_imports_through_the_real_pipeline(string $format, string $ext, string $parsedAs, int $rows, int $creditNotes, int $skipped): void
    {
        $customer = Customer::create(['name' => 'Sample', 'vat_no' => 'IT09876543210', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);
        $path = $this->download($format, $ext, $customer);

        $analysis = app(IncomeDataImportService::class)->analyze($path, "sample.{$ext}", $customer);

        $this->assertNull($analysis['error'], (string) $analysis['error']);
        $this->assertSame($parsedAs, $analysis['format']);
        $this->assertSame($rows, $analysis['new']);
        $this->assertSame($creditNotes, $analysis['credit_notes']);
        $this->assertSame($skipped, $analysis['skipped']);

        if ($format !== 'cash-register') {
            // Carries the customer's VAT number, so it is matched without choosing.
            $this->assertSame($customer->id, app(IncomeDataImportService::class)->analyze($path, "sample.{$ext}")['customer_id']);
        }
        unlink($path);
    }

    public function test_samples_are_staff_only_and_unknown_ones_404(): void
    {
        $customerUser = User::factory()->create(['customer_id' => Customer::create(['name' => 'X'])->id]);

        $this->actingAs($customerUser)->get(route('tax.import-sample', ['format' => 'invoices', 'ext' => 'csv']))->assertForbidden();
        $this->actingAs($this->admin)->get('/tax/import-samples/e-invoice.csv')->assertNotFound();
        auth()->logout();
        $this->get(route('tax.import-sample', ['format' => 'invoices', 'ext' => 'csv']))->assertRedirect();
    }
}
