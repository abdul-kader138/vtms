<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportIncome;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\IncomeImportBatch;
use App\Models\TaxCalculation;
use App\Models\User;
use App\Services\Tax\HistoricalYearEntryService;
use App\Services\Tax\Import\IncomeDataImportService;
use App\Services\Tax\TaxCalculationOrchestrator;
use Database\Seeders\InpsRateSlabSeeder;
use Database\Seeders\ShieldSeeder;
use Database\Seeders\TaxSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class IncomeImportTest extends TestCase
{
    use RefreshDatabase;

    private const SDI_HEADER = 'Tipo fattura;Tipo documento;Numero fattura / Documento;Data emissione;Data trasmissione;Codice fiscale fornitore;Partita IVA fornitore;Denominazione fornitore;Codice fiscale cliente;Partita IVA cliente;Denominazione cliente;Imponibile/Importo (totale in euro);Imposta (totale in euro);Sdi/file;Fatture consegnate;Data consegna/Presa visione;Bollo virtuale';

    private const DEVICE_HEADER = "Id invio;Matricola dispositivo;Data e ora rilevazione;Data e ora trasmissione;Ammontare delle vendite (totale in euro);Imponibile vendite (totale in euro);Imposta vendite (totale in euro);Periodo di inattivita' da;Periodo di inattivita' a";

    private Customer $customer;

    private Customer $other;

    private IncomeDataImportService $importer;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-01');
        Storage::fake('local');
        $this->seed(TaxSettingsSeeder::class);
        $this->seed(InpsRateSlabSeeder::class);

        $this->customer = Customer::create(['name' => 'Anna Bianchi', 'company' => 'Bianchi Design', 'vat_no' => 'IT 01234567890', 'customer_type' => 'gestione_separata', 'coefficient_of_profitability' => 78, 'tax_rate' => 15]);
        $this->other = Customer::create(['name' => 'Paolo Verdi', 'vat_no' => '09876543210', 'customer_type' => 'commercianti', 'coefficient_of_profitability' => 40, 'tax_rate' => 15]);
        $this->importer = app(IncomeDataImportService::class);
        $this->dir = sys_get_temp_dir().'/income-import-test-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function sdiRow(string $type, string $number, string $date, string $amount, string $sdi, string $seller = '01234567890', string $buyer = ''): string
    {
        return "'Fattura tra privati';{$type};'{$number}';{$date};{$date};'RSSMRA80A01H501U';'{$seller}';'Bianchi Design';'CF';'{$buyer}';'Cliente';\"{$amount}\";\"000000000000,00\";'{$sdi}';Consegnata;{$date};";
    }

    private function file(string $name, string $content): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $content);

        return $path;
    }

    private function sdiCsv(string $name = 'fatture.csv', ?array $rows = null): string
    {
        $rows ??= [
            $this->sdiRow('Fattura', '1', '15/01/2025', '000000001000,00', '1001'),
            $this->sdiRow('Fattura', '2', '20/02/2025', '000000000500,50', '1002'),
            $this->sdiRow('Nota di credito', 'NC1', '25/02/2025', '000000000100,00', '1003'),
        ];

        return $this->file($name, "\xEF\xBB\xBF".self::SDI_HEADER."\r\n".implode("\r\n", $rows)."\r\n");
    }

    private function invoiceXml(string $number, string $date, string $amount, string $type = 'TD01', string $seller = '01234567890'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>{$seller}</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>Bianchi Design</Denominazione></Anagrafica></DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici><CodiceFiscale>RSSMRA80A01H501U</CodiceFiscale><Anagrafica><Nome>Mario</Nome><Cognome>Rossi</Cognome></Anagrafica></DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento><TipoDocumento>{$type}</TipoDocumento><Divisa>EUR</Divisa><Data>{$date}</Data><Numero>{$number}</Numero><DatiBollo><BolloVirtuale>SI</BolloVirtuale><ImportoBollo>2.00</ImportoBollo></DatiBollo><ImportoTotaleDocumento>{$amount}</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi><DatiRiepilogo><AliquotaIVA>0.00</AliquotaIVA><Natura>N2.2</Natura><ImponibileImporto>{$amount}</ImponibileImporto><Imposta>0.00</Imposta></DatiRiepilogo></DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
    }

    public function test_invoice_list_is_matched_by_vat_and_credit_notes_reduce_revenue(): void
    {
        $path = $this->sdiCsv();

        $analysis = $this->importer->analyze($path, 'fatture.csv');
        $this->assertNull($analysis['error']);
        $this->assertSame($this->customer->id, $analysis['customer_id']);
        $this->assertSame('vat', $analysis['match']);
        $this->assertSame(3, $analysis['new']);
        $this->assertSame(1, $analysis['credit_notes']);
        $this->assertEqualsWithDelta(1400.50, $analysis['total'], 0.001);
        $this->assertSame(0, IncomeData::count(), 'analyze must not write');

        $batch = $this->importer->commit($path, 'fatture.csv', $this->customer);

        $this->assertSame(3, $batch->imported);
        $note = IncomeData::where('reference_no', 'NC1')->first();
        $this->assertSame('TD04', $note->document_type);
        $this->assertEquals(-100.00, (float) $note->sales_amount);
        $this->assertEquals(1400.50, (float) IncomeData::where('customer_id', $this->customer->id)->sum('sales_amount'));
        $this->assertSame($batch->id, $note->import_batch_id);
    }

    public function test_reimporting_skips_duplicates_row_by_row(): void
    {
        $this->importer->commit($this->sdiCsv(), 'fatture.csv', $this->customer);

        $bigger = $this->sdiCsv('fatture-full.csv', [
            $this->sdiRow('Fattura', '1', '15/01/2025', '000000001000,00', '1001'),
            $this->sdiRow('Fattura', '3', '10/03/2025', '000000000250,00', '1004'),
        ]);
        $analysis = $this->importer->analyze($bigger, 'fatture-full.csv');

        $this->assertSame(1, $analysis['new']);
        $this->assertSame(1, $analysis['duplicates']);
        $this->assertSame(1, $this->importer->commit($bigger, 'fatture-full.csv', $this->customer)->imported);
        $this->assertSame(4, IncomeData::count());
    }

    public function test_excel_resaved_csv_with_wrapped_lines_is_read(): void
    {
        $line = $this->sdiRow('Fattura', '7', '01/04/2025', '000000000122,95', '2001');
        // What Excel writes when it opens the ";" file as a "," CSV and saves:
        // the line is split at every comma and each piece is quoted.
        $wrapped = implode(',', array_map(fn ($piece) => '"'.str_replace('"', '""', $piece).'"', explode(',', $line)));
        $path = $this->file('excel.csv', self::SDI_HEADER.",,\r\n".$wrapped."\r\n");

        $analysis = $this->importer->analyze($path, 'excel.csv');

        $this->assertNull($analysis['error']);
        $this->assertSame(1, $analysis['new']);
        $this->assertEqualsWithDelta(122.95, $analysis['total'], 0.001);
    }

    public function test_same_invoice_from_xml_after_csv_is_a_duplicate(): void
    {
        $this->importer->commit($this->sdiCsv(), 'fatture.csv', $this->customer);

        $xml = $this->file('IT01234567890_00001.xml', $this->invoiceXml('1', '2025-01-15', '1000.00'));
        $analysis = $this->importer->analyze($xml, 'IT01234567890_00001.xml');

        $this->assertSame($this->customer->id, $analysis['customer_id']);
        $this->assertSame(0, $analysis['new']);
        $this->assertSame(1, $analysis['duplicates']);
    }

    public function test_zip_of_xml_and_p7m_invoices_with_metadata(): void
    {
        $zipPath = $this->dir.'/fatture.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('IT01234567890_a0001.xml', $this->invoiceXml('10', '2025-05-02', '300.00'));
        $zip->addFromString('IT01234567890_a0001.xml_metaDato.xml', '<?xml version="1.0"?><FileMetadati><IdentificativoSdI>99887766</IdentificativoSdI><NomeFile>IT01234567890_a0001.xml</NomeFile></FileMetadati>');
        $zip->addFromString('IT01234567890_a0002.xml.p7m', $this->signed($this->invoiceXml('11', '2025-05-03', '200.00')));
        $zip->addFromString('IT01234567890_a0003.xml', $this->invoiceXml('NC2', '2025-05-04', '50.00', 'TD04'));
        $zip->addFromString('../../evil.xml', $this->invoiceXml('12', '2025-05-05', '1.00', 'TD01', '09876543210'));
        $zip->close();

        $batch = $this->importer->commit($zipPath, 'fatture.zip', $this->customer);

        // "../../evil.xml" is read in memory only (nothing extracted), and as
        // another seller's invoice it is not this customer's revenue.
        $this->assertSame(4, $batch->rows_read);
        $this->assertSame(3, $batch->imported);
        $this->assertNull(IncomeData::where('reference_no', '12')->first());
        $this->assertSame('99887766', IncomeData::where('reference_no', '10')->first()->meta['sdi_id']);
        $this->assertEquals(-50.00, (float) IncomeData::where('reference_no', 'NC2')->first()->sales_amount);
        $this->assertNotNull(IncomeData::where('reference_no', '11')->first(), 'p7m content extracted');
        $this->assertFileDoesNotExist(dirname($this->dir).'/evil.xml');
    }

    public function test_a_file_with_several_sellers_is_not_auto_matched(): void
    {
        $zipPath = $this->dir.'/mixed.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('a.xml', $this->invoiceXml('1', '2025-05-02', '300.00'));
        $zip->addFromString('b.xml', $this->invoiceXml('1', '2025-05-02', '300.00', 'TD01', '09876543210'));
        $zip->close();

        $analysis = $this->importer->analyze($zipPath, 'mixed.zip');

        $this->assertNull($analysis['customer_id']);
        $this->assertStringContainsString('several customers', $analysis['error']);
    }

    public function test_file_of_another_customer_or_purchase_list_is_refused(): void
    {
        $analysis = $this->importer->analyze($this->sdiCsv(), 'fatture.csv', $this->other);
        $this->assertStringContainsString('belongs to Bianchi Design', $analysis['error']);

        $purchases = $this->sdiCsv('ricevute.csv', [$this->sdiRow('Fattura', '55', '01/02/2025', '000000000080,00', '3001', seller: '11111111111', buyer: '01234567890')]);
        $analysis = $this->importer->analyze($purchases, 'ricevute.csv', $this->customer);
        $this->assertStringContainsString('purchases', $analysis['error']);
    }

    public function test_cash_register_skips_empty_days_and_is_matched_by_serial_next_time(): void
    {
        $csv = fn (string $name, array $rows) => $this->file($name, self::DEVICE_HEADER."\n".implode("\n", $rows)."\n");
        $first = $csv('rt1.csv', [
            "'5001';'96SRT001';01/03/2025 20:00:00;01/03/2025 20:01:00;\"000000000150,00\";\"000000000150,00\";\"000000000000,00\";;",
            "'5002';'96SRT001';02/03/2025 20:00:00;02/03/2025 20:01:00;\"000000000000,00\";\"000000000000,00\";\"000000000000,00\";;",
        ]);

        $analysis = $this->importer->analyze($first, 'rt1.csv');
        $this->assertNull($analysis['customer_id']);
        $this->assertStringContainsString('96SRT001', $analysis['error']);

        $batch = $this->importer->commit($first, 'rt1.csv', $this->other);
        $this->assertSame(1, $batch->imported);
        $this->assertSame(1, $batch->skipped);

        $second = $csv('rt2.csv', ["'5003';'96SRT001';03/03/2025 20:00:00;03/03/2025 20:01:00;\"000000000090,00\";\"000000000090,00\";\"000000000000,00\";;"]);
        $analysis = $this->importer->analyze($second, 'rt2.csv');
        $this->assertSame($this->other->id, $analysis['customer_id']);
        $this->assertSame('device', $analysis['match']);
    }

    public function test_import_recalculates_calculated_years_and_undo_reverts(): void
    {
        IncomeData::create(['customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'transaction_date' => '2025-01-02', 'sales_amount' => 10000, 'taxable_sales' => 10000, 'sale_taxes' => 0, 'tax_year' => 2025]);
        app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customer, 2025);
        $this->assertEquals(10000, (float) TaxCalculation::where('customer_id', $this->customer->id)->where('tax_year', 2025)->value('total_sales'));

        $batch = $this->importer->commit($this->sdiCsv(), 'fatture.csv', $this->customer);

        $this->assertSame([2025], $batch->recalculated_years);
        $this->assertEquals(11400.50, (float) TaxCalculation::where('customer_id', $this->customer->id)->where('tax_year', 2025)->value('total_sales'));

        $this->importer->rollback($batch);

        $this->assertNotNull($batch->fresh()->rolled_back_at);
        $this->assertSame(1, IncomeData::count());
        $this->assertEquals(10000, (float) TaxCalculation::where('customer_id', $this->customer->id)->where('tax_year', 2025)->value('total_sales'));
    }

    public function test_hand_entered_year_is_not_overwritten(): void
    {
        app(HistoricalYearEntryService::class)->save($this->customer, 2025, ['tax' => [
            'total_sales' => 30000, 'taxable_income' => 23400, 'tax_due' => 3510, 'advance_payments_made' => 0, 'balance_payment' => 3510, 'next_year_advance_base' => 2808,
        ]]);

        $batch = $this->importer->commit($this->sdiCsv(), 'fatture.csv', $this->customer);

        $this->assertNull($batch->recalculated_years);
        $this->assertStringContainsString('entered by hand', implode(' ', $batch->warnings));
        $this->assertEquals(30000, (float) TaxCalculation::where('customer_id', $this->customer->id)->value('total_sales'));
    }

    public function test_xml_with_doctype_is_rejected(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'.substr($this->invoiceXml('1', '2025-01-01', '1.00'), strlen('<?xml version="1.0" encoding="UTF-8"?>'));
        $analysis = $this->importer->analyze($this->file('x.xml', $xxe), 'x.xml', $this->customer);

        $this->assertSame(0, $analysis['new']);
        $this->assertStringContainsString('could not be read', implode(' ', $analysis['warnings']));
    }

    public function test_received_invoices_list_and_excel_trailing_commas(): void
    {
        $received = $this->file('ricevute.csv', str_replace('Data consegna/Presa visione', 'Data ricezione', self::SDI_HEADER)."\n".$this->sdiRow('Fattura', '9', '01/02/2025', '000000000080,00', '4001', seller: '11111111111', buyer: '01234567890')."\n");
        $this->assertStringContainsString('received invoices', $this->importer->analyze($received, 'ricevute.csv')['error']);

        $commas = $this->file('commas.csv', self::SDI_HEADER.",,\n".$this->sdiRow('Fattura', '8', '01/02/2025', '000000000080,00', '4002')."\n");
        $this->assertSame(1, $this->importer->analyze($commas, 'commas.csv')['new']);
    }

    public function test_unknown_file_gives_a_clear_error(): void
    {
        $analysis = $this->importer->analyze($this->file('x.csv', "a;b;c\n1;2;3\n"), 'x.csv');

        $this->assertStringContainsString('Unrecognised file', $analysis['error']);
    }

    public function test_inbox_imports_matched_files_and_parks_the_rest(): void
    {
        $this->seed(ShieldSeeder::class);
        $disk = Storage::disk('local');
        $disk->put('income-imports/inbox/fatture.csv', file_get_contents($this->sdiCsv()));
        $disk->put('income-imports/inbox/unknown.csv', "a;b\n1;2\n");
        $disk->put('income-imports/inbox/'.$this->other->id.'/rt.csv', self::DEVICE_HEADER."\n'7001';'RT9';01/03/2025 20:00:00;01/03/2025 20:01:00;\"000000000050,00\";\"000000000050,00\";\"000000000000,00\";;\n");

        $this->artisan('tax:import-inbox')->assertExitCode(1);

        $this->assertSame(3, IncomeData::where('customer_id', $this->customer->id)->count());
        $this->assertSame(1, IncomeData::where('customer_id', $this->other->id)->count());
        $this->assertSame([], $disk->allFiles('income-imports/inbox'));
        $this->assertCount(2, $disk->allFiles('income-imports/processed'));
        $this->assertCount(2, $disk->files('income-imports/failed'), 'failed file + reason');
        $this->assertSame(2, IncomeImportBatch::where('channel', 'inbox')->count());

        $admin = User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->first();
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_inbox_run_prunes_abandoned_uploads_but_keeps_imported_ones(): void
    {
        $disk = Storage::disk('local');
        $disk->put(ImportIncome::UPLOAD_DIR.'/old.txt', 'x');
        $disk->put(ImportIncome::UPLOAD_DIR.'/kept.txt', file_get_contents($this->sdiCsv()));
        $this->importer->commit($disk->path(ImportIncome::UPLOAD_DIR.'/kept.txt'), 'fatture.csv', $this->customer, storedPath: ImportIncome::UPLOAD_DIR.'/kept.txt');
        $disk->put(ImportIncome::UPLOAD_DIR.'/fresh.txt', 'x');
        touch($disk->path(ImportIncome::UPLOAD_DIR.'/old.txt'), now()->subDays(2)->getTimestamp());
        touch($disk->path(ImportIncome::UPLOAD_DIR.'/kept.txt'), now()->subDays(2)->getTimestamp());

        $this->artisan('tax:import-inbox')->assertSuccessful();

        $this->assertFalse($disk->exists(ImportIncome::UPLOAD_DIR.'/old.txt'));
        $this->assertTrue($disk->exists(ImportIncome::UPLOAD_DIR.'/kept.txt'));
        $this->assertTrue($disk->exists(ImportIncome::UPLOAD_DIR.'/fresh.txt'));
    }

    public function test_fix_credit_notes_command_flips_old_positive_rows(): void
    {
        IncomeData::create(['customer_id' => $this->customer->id, 'source' => 'sdi_fattura', 'document_type' => 'TD04', 'transaction_date' => '2025-02-01', 'sales_amount' => 80, 'taxable_sales' => 80, 'sale_taxes' => 0, 'tax_year' => 2025]);

        $this->artisan('tax:fix-credit-notes --dry-run')->assertSuccessful();
        $this->assertEquals(80, (float) IncomeData::first()->sales_amount);

        $this->artisan('tax:fix-credit-notes')->assertSuccessful();
        $this->assertEquals(-80, (float) IncomeData::first()->sales_amount);
    }

    public function test_import_page_checks_and_imports_uploaded_files(): void
    {
        $this->seed(ShieldSeeder::class);
        $this->actingAs(User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail());
        Storage::disk('local')->put(ImportIncome::UPLOAD_DIR.'/abc.csv', file_get_contents($this->sdiCsv()));

        $page = Livewire::test(ImportIncome::class)
            ->set('data.names', [ImportIncome::UPLOAD_DIR.'/abc.csv' => 'fatture.csv'])
            ->assertOk();

        // The FileUpload state is set by Livewire's uploader in the browser;
        // here the stored path is injected the same way the form returns it.
        $page->set('previews', [['path' => ImportIncome::UPLOAD_DIR.'/abc.csv'] + $this->importer->analyze(Storage::disk('local')->path(ImportIncome::UPLOAD_DIR.'/abc.csv'), 'fatture.csv')])
            ->assertSee('Bianchi Design')
            ->assertSee(__('Matched by VAT number'))
            ->call('import')
            ->assertSet('previews', []);

        $this->assertSame(3, IncomeData::count());
        $batch = IncomeImportBatch::first();
        $this->assertSame(ImportIncome::UPLOAD_DIR.'/abc.csv', $batch->stored_path);

        Livewire::test(ImportIncome::class)
            ->assertSee('fatture.csv')
            ->callTableAction('undo', $batch);
        $this->assertSame(0, IncomeData::count());
    }

    public function test_import_page_refuses_paths_outside_the_upload_folder(): void
    {
        $this->seed(ShieldSeeder::class);
        $this->actingAs(User::where('email', env('ADMIN_EMAIL', 'admin@admin.com'))->firstOrFail());
        Storage::disk('local')->put('tax-receipts/secret.csv', file_get_contents($this->sdiCsv()));

        Livewire::test(ImportIncome::class)
            ->set('previews', [['path' => 'tax-receipts/secret.csv'] + $this->importer->analyze(Storage::disk('local')->path('tax-receipts/secret.csv'), 'x.csv')])
            ->call('import');

        $this->assertSame(0, IncomeData::count());
        $this->assertTrue(Storage::disk('local')->exists('tax-receipts/secret.csv'));
    }

    /** A CAdES-like signed envelope (DER), as SDI delivers .p7m files. */
    private function signed(string $xml): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test'], $key), null, $key, 1);
        $in = $this->file('in.xml', $xml);
        $out = $this->dir.'/out.p7m';
        openssl_cms_sign($in, $out, $cert, $key, [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
        $der = file_get_contents($out);
        unlink($in);
        unlink($out);

        return $der;
    }
}
