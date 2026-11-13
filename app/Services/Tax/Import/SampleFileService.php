<?php

namespace App\Services\Tax\Import;

use App\Models\Customer;
use App\Services\Tax\Import\Support\IncomeRow;
use App\Services\Tax\Import\Support\SampleAmount as Amount;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Example files in exactly the formats the importer reads — built from the
 * parsers' own header constants, so a sample can never drift from what the
 * import accepts (tests/Feature/ImportSampleFilesTest.php imports each one).
 *
 * The data is fictional and dated in the current year. Given a customer,
 * the samples carry that customer's VAT number so they match on import.
 */
class SampleFileService
{
    public const FORMATS = [
        'invoices' => ['csv', 'xlsx'],
        'cash-register' => ['csv', 'xlsx'],
        'e-invoice' => ['xml'],
    ];

    public function supports(string $format, string $ext): bool
    {
        return in_array($ext, self::FORMATS[$format] ?? [], true);
    }

    public function download(string $format, string $ext, ?Customer $customer = null): StreamedResponse
    {
        if (! $this->supports($format, $ext)) {
            throw new InvalidArgumentException("Unknown sample {$format}.{$ext}");
        }

        $name = 'vtms-sample-'.$format.($customer ? '-'.Str::slug($customer->company ?: $customer->name) : '').'.'.$ext;

        if ($ext === 'xml') {
            return response()->streamDownload(fn () => print ($this->invoiceXml($customer)), $name, ['Content-Type' => 'application/xml']);
        }

        [$header, $rows] = $format === 'invoices' ? $this->invoiceRows($customer) : $this->cashRegisterRows();

        if ($ext === 'csv') {
            return response()->streamDownload(function () use ($header, $rows) {
                // Same shape as the Agenzia delle Entrate export: ";"-separated,
                // amounts as quoted Italian decimals, codes as 'quoted' text.
                echo implode(';', $header)."\r\n";
                foreach ($rows as $row) {
                    echo implode(';', array_map(fn ($v) => $this->csvCell($v), $row))."\r\n";
                }
            }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($header, $rows) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setTitle(__('Sample'));
            $sheet->fromArray($header, null, 'A1');
            foreach ($rows as $i => $row) {
                $sheet->fromArray(array_map(fn ($v) => $v instanceof Amount ? $v->value : $v, $row), null, 'A'.($i + 2), true);
            }
            foreach (range(1, count($header)) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            (new Xlsx($book))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    public function invoiceRows(?Customer $customer = null): array
    {
        $vat = $this->vat($customer);
        $seller = $customer?->company ?: $customer?->name ?: 'Esempio Srl';
        $y = now()->year;

        $row = fn (string $type, string $number, string $date, float $amount, string $sdi, string $buyer) => [
            "'Fattura tra privati'", $type, "'{$number}'", $date, $date, "'".($customer?->codice_fiscale ?: 'RSSMRA80A01H501U')."'",
            "'{$vat}'", "'{$seller}'", "'".'BNCLRA85M41F205X'."'", "''", "'{$buyer}'",
            new Amount($amount), new Amount(0), "'{$sdi}'", 'Consegnata', $date, $amount > 77.47 ? 'Si' : '',
        ];

        return [SdiFatturaImportParser::HEADER, [
            $row('Fattura', '1', "15/01/{$y}", 640.00, '9000000001', 'Laura Bianchi'),
            $row('Fattura', '2', "03/02/{$y}", 75.00, '9000000002', 'Marco Verdi'),
            $row('Nota di credito', 'NC1', "10/02/{$y}", 90.00, '9000000003', 'Laura Bianchi'),
        ]];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    public function cashRegisterRows(): array
    {
        $y = now()->year;
        $row = fn (string $id, string $day, float $sales, float $taxable) => [
            "'{$id}'", "'96SRT000001'", "{$day}/03/{$y} 19:30:00", "{$day}/03/{$y} 19:31:12",
            new Amount($sales), new Amount($taxable), new Amount(0), '', '',
        ];

        return [PrivatiDeviceImportParser::HEADER, [
            $row('70000001', '02', 412.50, 412.50),
            $row('70000002', '03', 0, 0), // closed day: the register still sends a zero total
            $row('70000003', '04', 268.00, 268.00),
        ]];
    }

    public function invoiceXml(?Customer $customer = null): string
    {
        $vat = $this->vat($customer);
        $seller = e($customer?->company ?: $customer?->name ?: 'Esempio Srl');
        $date = now()->year.'-01-15';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!-- VTMS sample: a FatturaPA e-invoice (forfettario: no VAT, virtual stamp duty). Fictional data. -->
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <DatiTrasmissione><IdTrasmittente><IdPaese>IT</IdPaese><IdCodice>{$vat}</IdCodice></IdTrasmittente><ProgressivoInvio>00001</ProgressivoInvio><FormatoTrasmissione>FPR12</FormatoTrasmissione><CodiceDestinatario>0000000</CodiceDestinatario></DatiTrasmissione>
    <CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>{$vat}</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>{$seller}</Denominazione></Anagrafica><RegimeFiscale>RF19</RegimeFiscale></DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici><CodiceFiscale>BNCLRA85M41F205X</CodiceFiscale><Anagrafica><Nome>Laura</Nome><Cognome>Bianchi</Cognome></Anagrafica></DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>{$date}</Data><Numero>1</Numero><DatiBollo><BolloVirtuale>SI</BolloVirtuale><ImportoBollo>2.00</ImportoBollo></DatiBollo><ImportoTotaleDocumento>642.00</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi>
      <DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>Servizio di esempio</Descrizione><PrezzoUnitario>640.00</PrezzoUnitario><PrezzoTotale>640.00</PrezzoTotale><AliquotaIVA>0.00</AliquotaIVA><Natura>N2.2</Natura></DettaglioLinee>
      <DatiRiepilogo><AliquotaIVA>0.00</AliquotaIVA><Natura>N2.2</Natura><ImponibileImporto>640.00</ImponibileImporto><Imposta>0.00</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
    }

    private function vat(?Customer $customer): string
    {
        $vat = IncomeRow::vat($customer?->vat_no);

        return preg_match('/^\d{11}$/', $vat) ? $vat : '01234567890';
    }

    private function csvCell(mixed $value): string
    {
        if ($value instanceof Amount) {
            // "000000000640,00" — the export's zero-padded Italian decimal, quoted.
            return '"'.str_pad(number_format($value->value, 2, ',', ''), 15, '0', STR_PAD_LEFT).'"';
        }

        return (string) $value;
    }
}
