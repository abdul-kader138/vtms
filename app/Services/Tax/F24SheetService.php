<?php

namespace App\Services\Tax;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Support\Tax\F24Code;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Prospetto F24": everything a customer owes on one deadline, laid out
 * line by line as it is typed into home banking / the F24 form — section,
 * code, reference year, amount — plus, when paid late, the ravvedimento
 * interest and penalty lines.
 */
class F24SheetService
{
    public function __construct(
        private readonly PaymentCalendarService $calendar,
        private readonly RavvedimentoService $ravvedimento,
    ) {}

    /**
     * @return array{customer: Customer, due_date: CarbonInterface, pay_on: CarbonInterface, late_days: int,
     *     sections: Collection, total: float, notes: list<string>}
     */
    public function build(Customer $customer, CarbonInterface $dueDate, CarbonInterface $payOn): array
    {
        $entries = $this->calendar->entries($dueDate, $dueDate, status: 'open', customerId: $customer->id)
            ->filter(fn ($e) => $e['outstanding'] > 0);

        $lines = collect();
        $notes = [];

        foreach ($entries as $e) {
            $payment = $e['payment'];
            $line = [
                'section' => F24Code::section($e['type']),
                'description' => $e['description'],
                'code' => $e['f24'],
                'year' => $this->referenceYear($e['type'], $payment),
                'period' => $this->inpsPeriod($e['type'], $payment, (string) $e['f24']),
                'office' => match ($e['type']) {
                    'inps' => $customer->customer_type === CustomerType::GestioneSeparata ? null : $customer->inps_office_code,
                    'diritto_annuale' => $customer->chamber_province ? strtoupper($customer->chamber_province) : null,
                    default => null,
                },
                'account' => $e['type'] === 'inps' && $customer->customer_type !== CustomerType::GestioneSeparata ? $customer->inps_code : null,
                'amount' => (float) $e['outstanding'],
            ];
            $lines->push($line);

            if ($payOn->gt($dueDate)) {
                $late = $this->ravvedimento->calculate($e['type'], (float) $e['outstanding'], $dueDate, $payOn);

                if ($late['applicable']) {
                    foreach (['interest' => __('interest'), 'sanction' => __('penalty (ravvedimento :r)', ['r' => $late['reduction']])] as $key => $label) {
                        if ($late[$key] > 0) {
                            $lines->push(['code' => $late[$key.'_code'], 'description' => $e['description'].' — '.$label, 'amount' => $late[$key], 'period' => null] + $line);
                        }
                    }
                } elseif ($late['reason']) {
                    $notes[] = $late['reason'];
                }
            }
        }

        $sections = $lines->groupBy('section')->map(fn (Collection $rows, string $name) => [
            'name' => $name,
            'lines' => $rows->values(),
            'total' => round((float) $rows->sum('amount'), 2),
        ])->values();

        $hasInps = $lines->contains(fn ($l) => $l['section'] === F24Code::section('inps'));
        if ($hasInps && $customer->customer_type !== CustomerType::GestioneSeparata && (blank($customer->inps_office_code) || blank($customer->inps_code))) {
            $notes[] = __('INPS section: add the INPS office code (codice sede) and the 17-digit codice INPS to the customer — they are on the INPS payment notice.');
        }
        if ($lines->contains(fn ($l) => in_array($l['code'], ['AF', 'CF'], true))) {
            $notes[] = __('Fixed INPS instalments (AF/CF): copy the period from the INPS payment notice.');
        }
        if ($lines->contains(fn ($l) => $l['section'] === F24Code::section('diritto_annuale')) && blank($customer->chamber_province)) {
            $notes[] = __('Diritto Annuale: add the Chamber of Commerce province ("codice ente", e.g. FI, MI) to the customer.');
        }
        if (blank($customer->codice_fiscale)) {
            $notes[] = __('Add the customer\'s codice fiscale: it goes in the F24 header.');
        }

        return [
            'customer' => $customer,
            'due_date' => $dueDate,
            'pay_on' => $payOn,
            'late_days' => (int) max(0, $dueDate->copy()->startOfDay()->diffInDays($payOn->copy()->startOfDay(), false)),
            'sections' => $sections,
            'total' => round((float) $lines->sum('amount'), 2),
            'notes' => array_values(array_unique($notes)),
        ];
    }

    public function filename(Customer $customer, CarbonInterface $dueDate): string
    {
        return 'F24_'.Str::slug($customer->company ?: $customer->name, '_').'_'.$dueDate->format('Y-m-d').'.pdf';
    }

    /** "Anno di riferimento": the tax year the amount belongs to. */
    private function referenceYear(string $type, $payment): ?int
    {
        return match ($type) {
            'tax' => (int) $payment->tax_year,
            default => isset($payment->payment_year) ? (int) $payment->payment_year : null,
        };
    }

    /**
     * INPS "periodo di riferimento" (mm/aaaa da – a) for contributions on
     * income above the minimale and Gestione Separata: the whole year they
     * belong to. Fixed AF/CF instalments are left to the INPS notice.
     */
    private function inpsPeriod(string $type, $payment, string $code): ?string
    {
        if ($type !== 'inps' || ! in_array($code, ['AP', 'CP', 'PXX', 'P10'], true)) {
            return null;
        }

        return sprintf('01/%1$d – 12/%1$d', $payment->payment_year);
    }
}
