<?php

namespace App\Services\Tax\Import;

use App\Enums\IncomeSource;
use App\Services\Tax\Import\Support\ImportValueNormalizer as Norm;
use App\Services\Tax\Import\Support\IncomeRow;
use App\Support\Tax\InvoiceDocumentType;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use ZipArchive;

/**
 * FatturaPA e-invoices — the legal source document, not a summary list:
 *  - a single invoice: .xml, or the signed .xml.p7m (CAdES) as stored in the
 *    "cassetto fiscale";
 *  - a .zip of them, e.g. the bulk download from "Fatture e Corrispettivi"
 *    or a provider export. The portal's "_metaDato.xml" files alongside are
 *    read for the SDI identifier.
 *
 * One file can hold several invoices (a "lotto": many FatturaElettronicaBody
 * under one header). Amounts come from DatiRiepilogo (taxable + VAT per rate)
 * so they match the CSV list's "Imponibile/Importo" for the same invoice.
 */
class FatturaPaXmlImportParser implements IncomeDataImportParser
{
    private const MAX_ZIP_ENTRIES = 5000;

    private const MAX_ENTRY_BYTES = 10 * 1024 * 1024;

    public static function handles(string $name): bool
    {
        return (bool) preg_match('/\.(xml|p7m|zip)$/i', $name);
    }

    public function parse(string $filePath, ?string $originalName = null): ParsedFile
    {
        $name = $originalName ?? basename($filePath);
        $documents = str_ends_with(strtolower($name), '.zip')
            ? $this->zipEntries($filePath)
            : [$name => (string) file_get_contents($filePath)];

        $rows = collect();
        $sdiIds = [];
        $warnings = [];
        $unreadable = [];
        $invoices = [];

        foreach ($documents as $entry => $content) {
            $xml = $this->unwrap($content);
            if ($xml === null) {
                $unreadable[] = $entry;

                continue;
            }

            $dom = $this->load($xml);
            if ($dom === null) {
                $unreadable[] = $entry;

                continue;
            }

            $root = $dom->documentElement->localName;
            if ($root === 'FileMetadati') {
                $xp = new DOMXPath($dom);
                $file = $this->text($xp, '//*[local-name()="NomeFile"]');
                $id = $this->text($xp, '//*[local-name()="IdentificativoSdI"]');
                if ($file && $id) {
                    $sdiIds[$this->baseName($file)] = $id;
                }

                continue;
            }
            if ($root !== 'FatturaElettronica') {
                continue; // receipts, notifications, other XML — not invoices
            }

            $invoices[$entry] = $dom;
        }

        foreach ($invoices as $entry => $dom) {
            foreach ($this->rowsFrom($dom, $entry, $sdiIds[$this->baseName($entry)] ?? null) as $row) {
                $rows->push($row);
            }
        }

        if ($invoices === [] && $unreadable === []) {
            throw new InvalidArgumentException(__('No FatturaPA invoices were found in this file.'));
        }
        if ($unreadable !== []) {
            $warnings[] = __(':n files could not be read and were skipped: :files', [
                'n' => count($unreadable),
                'files' => implode(', ', array_slice($unreadable, 0, 5)).(count($unreadable) > 5 ? ', …' : ''),
            ]);
        }

        return new ParsedFile(
            format: 'fatturapa_xml',
            rows: $rows,
            sellerVats: $rows->pluck('meta.partita_iva_fornitore')->filter()->unique()->values()->all(),
            skipped: count($unreadable),
            warnings: $warnings,
        );
    }

    /** @return list<array<string, mixed>> */
    private function rowsFrom(DOMDocument $dom, string $entry, ?string $sdiId): array
    {
        $xp = new DOMXPath($dom);
        $seller = '//*[local-name()="CedentePrestatore"]/*[local-name()="DatiAnagrafici"]';
        $buyer = '//*[local-name()="CessionarioCommittente"]/*[local-name()="DatiAnagrafici"]';

        $sellerVat = IncomeRow::vat(
            $this->text($xp, $seller.'/*[local-name()="IdFiscaleIVA"]/*[local-name()="IdPaese"]')
            .$this->text($xp, $seller.'/*[local-name()="IdFiscaleIVA"]/*[local-name()="IdCodice"]')
        );
        $buyerVatCode = $this->text($xp, $buyer.'/*[local-name()="IdFiscaleIVA"]/*[local-name()="IdCodice"]');
        $buyerVat = $buyerVatCode ? IncomeRow::vat($this->text($xp, $buyer.'/*[local-name()="IdFiscaleIVA"]/*[local-name()="IdPaese"]').$buyerVatCode) : '';
        $buyerName = $this->text($xp, $buyer.'//*[local-name()="Denominazione"]')
            ?: trim($this->text($xp, $buyer.'//*[local-name()="Nome"]').' '.$this->text($xp, $buyer.'//*[local-name()="Cognome"]'));

        $rows = [];

        foreach ($xp->query('//*[local-name()="FatturaElettronicaBody"]') as $body) {
            /** @var DOMElement $body */
            $doc = './*[local-name()="DatiGenerali"]/*[local-name()="DatiGeneraliDocumento"]';
            $type = InvoiceDocumentType::normalize($this->text($xp, $doc.'/*[local-name()="TipoDocumento"]', $body));
            $date = Norm::dateOnly($this->text($xp, $doc.'/*[local-name()="Data"]', $body));
            $number = $this->text($xp, $doc.'/*[local-name()="Numero"]', $body);

            if ($date === null) {
                continue;
            }

            $taxable = 0.0;
            $vat = 0.0;
            $summaries = $xp->query('./*[local-name()="DatiBeniServizi"]/*[local-name()="DatiRiepilogo"]', $body);
            foreach ($summaries as $summary) {
                $taxable += (float) $this->text($xp, './*[local-name()="ImponibileImporto"]', $summary);
                $vat += (float) $this->text($xp, './*[local-name()="Imposta"]', $summary);
            }
            if ($summaries->length === 0) {
                $taxable = (float) $this->text($xp, $doc.'/*[local-name()="ImportoTotaleDocumento"]', $body);
            }

            $credit = InvoiceDocumentType::isCreditNote($type);
            $sign = $credit ? -1 : 1;
            $taxable = round($sign * abs($taxable), 2);
            $vat = round($sign * abs($vat), 2);

            $rows[] = [
                'source' => IncomeSource::SdiFattura,
                'document_type' => $type,
                'transaction_date' => Carbon::instance($date),
                'sales_amount' => $taxable,
                'taxable_sales' => $taxable,
                'sale_taxes' => $vat,
                'reference_no' => $number ?: null,
                // A lotto shares one SDI id across invoices, so it can't be
                // the unique external id; duplicates are caught by fingerprint.
                'external_id' => null,
                'fingerprint' => IncomeRow::invoiceFingerprint($sellerVat, $credit, $number, $date),
                'meta' => array_filter([
                    'format' => 'fatturapa_xml',
                    'file' => $entry,
                    'sdi_id' => $sdiId,
                    'tipo_documento' => $type,
                    'numero_fattura' => $number,
                    'partita_iva_fornitore' => $sellerVat,
                    'denominazione_fornitore' => $this->text($xp, $seller.'//*[local-name()="Denominazione"]'),
                    'partita_iva_cliente' => $buyerVat,
                    'codice_fiscale_cliente' => $this->text($xp, $buyer.'/*[local-name()="CodiceFiscale"]'),
                    'denominazione_cliente' => $buyerName,
                    'importo_totale' => $this->text($xp, $doc.'/*[local-name()="ImportoTotaleDocumento"]', $body),
                    'bollo_virtuale' => $this->text($xp, $doc.'/*[local-name()="DatiBollo"]/*[local-name()="BolloVirtuale"]', $body),
                ], fn ($v) => $v !== null && $v !== ''),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> entry name => bytes */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException(__('The ZIP file could not be opened.'));
        }

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw new InvalidArgumentException(__('The ZIP file has too many files (max :n).', ['n' => self::MAX_ZIP_ENTRIES]));
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            $base = basename($name);

            // Read in memory only — never extracted to disk, so entry paths
            // ("../../x") can't escape anywhere.
            if (str_ends_with($name, '/') || str_starts_with($base, '.') || str_contains($name, '__MACOSX')
                || ! preg_match('/\.(xml|p7m)$/i', $base) || ($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                $entries[$base] = $content;
            }
        }
        $zip->close();

        return $entries;
    }

    /** The XML inside a .p7m (DER or base64), or the content itself if it is XML. */
    private function unwrap(string $content): ?string
    {
        $trimmed = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $content));
        if (str_starts_with($trimmed, '<')) {
            return $trimmed;
        }

        // Base64-encoded p7m (some providers send it that way).
        $compact = preg_replace('/\s+/', '', $content);
        if ($compact !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact)) {
            $decoded = base64_decode($compact, true);
            if ($decoded !== false) {
                $content = $decoded;
            }
        }

        // Signed envelope: let OpenSSL extract the payload. The signature is
        // not validated here — SDI already did that before delivery.
        if (function_exists('openssl_cms_verify')) {
            $in = tempnam(sys_get_temp_dir(), 'p7m');
            $out = tempnam(sys_get_temp_dir(), 'xml');
            try {
                file_put_contents($in, $content);
                if (@openssl_cms_verify($in, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $out, null, null, OPENSSL_ENCODING_DER)) {
                    $xml = (string) file_get_contents($out);
                    if (str_contains($xml, 'FatturaElettronica')) {
                        return ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $xml));
                    }
                }
            } finally {
                @unlink($in);
                @unlink($out);
            }
        }

        // Fallback: the XML sits as one block inside the DER structure.
        if (preg_match('/<\?xml.*<\/(?:\w+:)?FatturaElettronica>/s', $content, $m)) {
            return $m[0];
        }

        return null;
    }

    private function load(string $xml): ?DOMDocument
    {
        // No DTDs: blocks entity-expansion and external-entity tricks.
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok && $dom->documentElement ? $dom : null;
    }

    private function text(DOMXPath $xp, string $query, ?\DOMNode $context = null): string
    {
        $node = $xp->query($query, $context)?->item(0);

        return $node ? trim($node->textContent) : '';
    }

    /** "IT0123_abc.xml.p7m" and "IT0123_abc.xml_metaDato.xml" → "IT0123_abc". */
    private function baseName(string $name): string
    {
        return preg_replace('/(\.xml)?(\.p7m)?(_metaDato\.xml)?$/i', '', basename($name));
    }
}
