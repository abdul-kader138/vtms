<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Enums\PaymentStatus;
use App\Enums\TaxPaymentType;
use App\Models\Customer;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Spreadsheet template for the historical-year form — VTMS's
 * historical_year_template(), extended so a filled-in template can be
 * uploaded back to pre-fill the form instead of being copied by hand.
 * Field keys are the form's own state paths (e.g. "tax.total_sales",
 * "tax_payments.balance.amount", "inps_payments.2.due_date").
 */
class HistoricalYearTemplateService
{
    public const SHEET = 'Historical Year Data';

    private const HEADERS = ['Field Key', 'Section', 'Label', 'Type', 'Allowed Values', 'Value', 'Notes'];

    private const VALUE_COLUMN = 'F';

    private const PAYMENT_FIELDS = [
        'amount' => ['Amount', 'number'],
        'due_date' => ['Due date', 'date'],
        'status' => ['Status', 'select'],
        'paid_amount' => ['Paid amount', 'number'],
        'paid_date' => ['Paid date', 'date'],
    ];

    /** @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}> */
    public function rows(Customer $customer): array
    {
        $rows = [
            ['year', 'General', 'Tax year', 'number', '', (string) (now()->year - 1), 'Required'],
            ['tax.coefficient_used', 'Tax calculation', 'Coefficient used (%)', 'number', '', (string) $customer->coefficient_of_profitability, 'Defaults to the customer setting'],
            ['tax.tax_rate_used', 'Tax calculation', 'Tax rate used (%)', 'number', '', (string) $customer->tax_rate, 'Defaults to the customer setting'],
        ];

        foreach (['total_sales' => 'Total sales', 'taxable_income' => 'Taxable income', 'tax_due' => 'Tax due', 'advance_payments_made' => 'Advance payments made', 'balance_payment' => 'Balance payment', 'next_year_advance_base' => 'Next year advance base'] as $field => $label) {
            $rows[] = ["tax.{$field}", 'Tax calculation', $label, 'number', '', '', ''];
        }

        foreach (TaxPaymentType::cases() as $type) {
            $rows = [...$rows, ...$this->paymentRows("tax_payments.{$type->value}", 'Tax payments', str_replace('_', ' ', ucfirst($type->value)))];
        }

        foreach (['taxable_income' => 'INPS taxable income', 'inps_amount' => 'INPS amount', 'inps_rate' => 'Average rate (%)', 'discount_percentage' => 'Discount (%)', 'discount_amount' => 'Discount amount', 'inps_amount_after_discount' => 'INPS after discount'] as $field => $label) {
            $rows[] = ["inps.{$field}", 'INPS', $label, 'number', '', '', ''];
        }

        $installments = $customer->customer_type === CustomerType::GestioneSeparata ? 3 : 4;
        for ($n = 1; $n <= $installments; $n++) {
            $label = $customer->customer_type === CustomerType::GestioneSeparata
                ? ['Saldo', '1st acconto', '2nd acconto'][$n - 1]
                : "Installment {$n}";
            $rows = [...$rows, ...$this->paymentRows("inps_payments.{$n}", 'INPS payments', $label)];
        }

        if ($customer->customer_type === CustomerType::Artigiani) {
            foreach (['taxable_income' => 'INAIL taxable income', 'inail_rate' => 'INAIL rate (%)', 'inail_calculated_amount' => 'Calculated amount', 'inail_minimum_payment' => 'Minimum payment', 'inail_final_amount' => 'Final amount'] as $field => $label) {
                $rows[] = ["inail.{$field}", 'INAIL', $label, 'number', '', '', ''];
            }
            $rows[] = ['inail.ateco_code', 'INAIL', 'ATECO code', 'text', '', (string) $customer->inail_ateco_code, ''];
            $rows = [...$rows, ...$this->paymentRows('inail_payment', 'INAIL payment', 'INAIL')];
        }

        if (in_array($customer->customer_type, [CustomerType::Commercianti, CustomerType::Artigiani], true)) {
            $rows = [...$rows, ...$this->paymentRows('diritto_annuale_payment', 'Diritto Annuale', 'Diritto Annuale')];
        }

        foreach (['total_invoices' => ['Total invoices', 'number'], 'total_sales_amount' => ['Total sales amount', 'number'], 'payment_per_invoice' => ['Fee per invoice', 'number'], 'total_payment_amount' => ['Total payment amount', 'number'], 'minimum_invoice_amount' => ['Qualifying threshold', 'number']] as $field => [$label, $type]) {
            $rows[] = ["fattura_tra_privati.{$field}", 'Fattura Tra Privati', $label, $type, '', '', 'Optional section'];
        }
        $rows = [...$rows, ...$this->paymentRows('fattura_tra_privati_payment', 'Fattura Tra Privati', 'Stamp duty')];

        return $rows;
    }

    private function paymentRows(string $prefix, string $section, string $label): array
    {
        $statuses = implode(',', array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()));
        $rows = [];

        foreach (self::PAYMENT_FIELDS as $field => [$fieldLabel, $type]) {
            $rows[] = [
                "{$prefix}.{$field}",
                $section,
                "{$label} — {$fieldLabel}",
                $type,
                $type === 'select' ? $statuses : '',
                $type === 'select' ? 'pending' : '',
                match ($field) {
                    'due_date' => 'YYYY-MM-DD. Leave blank for the standard due date',
                    'paid_date' => 'YYYY-MM-DD',
                    'amount' => 'Leave blank to skip this payment',
                    default => '',
                },
            ];
        }

        return $rows;
    }

    public function writeXlsx(Customer $customer, string $path): void
    {
        $spreadsheet = new Spreadsheet;

        $info = $spreadsheet->getActiveSheet()->setTitle('Instructions');
        $instructions = [
            ['Template', 'Historical year data'],
            ['Customer', $customer->company ?: $customer->name],
            ['Customer type', $customer->customer_type?->label() ?? '—'],
            ['How to use', 'Fill the "Value" column on the "'.self::SHEET.'" sheet, then upload this file on the historical year page to pre-fill the form. Review and save there.'],
            ['Dates', 'YYYY-MM-DD (Excel date cells also work)'],
            ['Do not change', 'The "Field Key" column — it is how values are matched back to the form.'],
        ];
        foreach ($instructions as $i => [$label, $value]) {
            $info->setCellValue('A'.($i + 1), $label)->setCellValue('B'.($i + 1), $value);
        }
        $info->getStyle('A1:A'.count($instructions))->getFont()->setBold(true);
        $info->getColumnDimension('A')->setWidth(18);
        $info->getColumnDimension('B')->setWidth(100);

        $sheet = $spreadsheet->createSheet()->setTitle(self::SHEET);
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');

        $line = 2;
        $currentSection = null;
        foreach ($this->rows($customer) as [$key, $section, $label, $type, $allowed, $default, $notes]) {
            if ($section !== $currentSection) {
                $currentSection = $section;
                $sheet->setCellValue("B{$line}", $section);
                $sheet->getStyle("A{$line}:G{$line}")->getFont()->setBold(true);
                $sheet->getStyle("A{$line}:G{$line}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
                $line++;
            }

            $sheet->fromArray([$key, $section, $label, $type, $allowed], null, "A{$line}");
            $sheet->setCellValueExplicit(self::VALUE_COLUMN.$line, $default, DataType::TYPE_STRING);
            $sheet->setCellValue("G{$line}", $notes);

            if ($type === 'select') {
                $this->addListValidation($sheet, self::VALUE_COLUMN.$line, $allowed);
            }

            $line++;
        }

        foreach (['A' => 34, 'B' => 20, 'C' => 32, 'D' => 9, 'E' => 30, 'F' => 18, 'G' => 48] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A2');
        $spreadsheet->setActiveSheetIndex(1);

        (new Xlsx($spreadsheet))->save($path);
    }

    private function addListValidation(Worksheet $sheet, string $cell, string $allowed): void
    {
        $validation = $sheet->getCell($cell)->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setFormula1('"'.$allowed.'"');
    }

    /**
     * Reads a filled template (xlsx, xls, ods or csv) into form state for
     * HistoricalYearEntry. Unknown keys are ignored.
     */
    public function readFormState(string $path, Customer $customer): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException(__('Could not read the spreadsheet: :error', ['error' => $e->getMessage()]));
        }

        $sheet = $spreadsheet->getSheetByName(self::SHEET) ?? $spreadsheet->getActiveSheet();
        $types = collect($this->rows($customer))->mapWithKeys(fn ($row) => [$row[0] => $row[3]]);

        $values = [];
        foreach ($sheet->toArray(null, false, false, false) as $row) {
            $key = trim((string) ($row[0] ?? ''));
            if ($key === '' || ! $types->has($key)) {
                continue;
            }

            $value = $row[5] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $values[$key] = $this->normalize($value, $types[$key]);
        }

        if (! isset($values['year'])) {
            throw new RuntimeException(__('The template is missing the tax year (or is not a historical-year template).'));
        }

        return $this->toFormState($values, (int) $values['year'], $customer);
    }

    private function normalize(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number' => is_numeric($value) ? (float) $value : (float) str_replace(['€', ' ', '.', ','], ['', '', '', '.'], (string) $value),
            'date' => is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString()
                : Carbon::parse((string) $value)->toDateString(),
            'select' => strtolower(trim((string) $value)),
            default => trim((string) $value),
        };
    }

    private function toFormState(array $values, int $year, Customer $customer): array
    {
        $state = ['year' => $year, 'tax_payments' => [], 'inps_payments' => []];

        foreach ($values as $key => $value) {
            $parts = explode('.', $key);

            if (in_array($parts[0], ['tax', 'inps', 'inail', 'fattura_tra_privati', 'inail_payment', 'diritto_annuale_payment', 'fattura_tra_privati_payment'], true) && count($parts) === 2) {
                $state[$parts[0]][$parts[1]] = $value;
            }
        }

        foreach (TaxPaymentType::cases() as $type) {
            $item = $this->collectPayment($values, "tax_payments.{$type->value}");
            if ($item !== null) {
                $item['due_date'] ??= $this->defaultDueDate('tax_payments', $type->value, $year, $customer);
                $state['tax_payments'][] = ['payment_type' => $type->value] + $item;
            }
        }

        for ($n = 1; $n <= 4; $n++) {
            $item = $this->collectPayment($values, "inps_payments.{$n}");
            if ($item !== null) {
                $item['due_date'] ??= $this->defaultDueDate('inps_payments', (string) $n, $year, $customer);
                $state['inps_payments'][] = ['installment_number' => $n] + $item;
            }
        }

        foreach (['inail_payment', 'diritto_annuale_payment', 'fattura_tra_privati_payment'] as $single) {
            if (isset($state[$single]['amount'])) {
                $state[$single]['due_date'] ??= $this->defaultDueDate($single, '', $year, $customer);
                $state[$single]['status'] ??= PaymentStatus::Pending->value;
            } else {
                unset($state[$single]);
            }
        }

        return $state;
    }

    private function collectPayment(array $values, string $prefix): ?array
    {
        if (! isset($values["{$prefix}.amount"])) {
            return null;
        }

        $item = [];
        foreach (array_keys(self::PAYMENT_FIELDS) as $field) {
            if (isset($values["{$prefix}.{$field}"])) {
                $item[$field] = $values["{$prefix}.{$field}"];
            }
        }
        $item['status'] ??= PaymentStatus::Pending->value;

        return $item;
    }

    /** Same schedule PaymentScheduleService uses for calculated years. */
    public function defaultDueDate(string $group, string $which, int $year, Customer $customer): string
    {
        $next = $year + 1;

        return match ($group) {
            'tax_payments' => match ($which) {
                'balance' => "{$next}-02-16",
                'first_advance' => "{$next}-06-30",
                default => "{$next}-11-30",
            },
            'inps_payments' => $customer->customer_type === CustomerType::GestioneSeparata
                ? [1 => "{$next}-06-30", 2 => "{$next}-06-30", 3 => "{$next}-11-30"][(int) $which] ?? "{$next}-11-30"
                : [1 => "{$year}-05-16", 2 => "{$year}-08-20", 3 => "{$year}-11-16", 4 => "{$next}-02-16"][(int) $which] ?? "{$next}-02-16",
            'diritto_annuale_payment' => "{$year}-03-31",
            default => "{$next}-02-16",
        };
    }
}
