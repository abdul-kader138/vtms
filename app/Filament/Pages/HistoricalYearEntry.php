<?php

namespace App\Filament\Pages;

use App\Enums\CustomerType;
use App\Enums\PaymentStatus;
use App\Enums\TaxPaymentType;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Tax\HistoricalYearEntryService;
use App\Services\Tax\HistoricalYearTemplateService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Manual backfill for a prior year — mirrors VTMS's "historical year" admin
 * form. Writes directly through HistoricalYearEntryService, bypassing the
 * automatic calculators entirely.
 */
class HistoricalYearEntry extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.historical-year-entry';

    protected static bool $shouldRegisterNavigation = false;

    // See CustomerTaxDashboard::mount() for why this isn't named $customer.
    public Customer $customerRecord;

    public ?array $data = [];

    public static function getRoutePath(): string
    {
        return '/customers/{customer}/tax/historical';
    }

    public function mount(int|string $customer): void
    {
        $this->customerRecord = Customer::findOrFail((int) $customer);

        if ($this->customerRecord->customer_type === null || $this->customerRecord->coefficient_of_profitability === null || $this->customerRecord->tax_rate === null) {
            Notification::make()
                ->warning()
                ->title(__('Tax settings required'))
                ->body(__('Configure customer type, coefficient of profitability, and tax rate before entering historical data.'))
                ->send();

            $this->redirect(CustomerResource::getUrl('edit', ['record' => $this->customerRecord->id]));

            return;
        }

        $this->form->fill(['year' => now()->year - 1]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label(__('Download Excel template'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $path = tempnam(sys_get_temp_dir(), 'histtpl');
                    app(HistoricalYearTemplateService::class)->writeXlsx($this->customerRecord, $path);

                    return response()->download($path, 'historical_year_template_customer_'.$this->customerRecord->id.'.xlsx')->deleteFileAfterSend();
                }),
            Action::make('importTemplate')
                ->label(__('Fill from template'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalDescription(__('Upload a filled-in template to pre-fill this form. Nothing is saved until you review and click "Save historical year".'))
                ->form([
                    FileUpload::make('file')
                        ->label(__('Template file'))
                        ->disk('local')
                        ->directory('tax-imports')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'application/vnd.oasis.opendocument.spreadsheet',
                            'text/csv',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        $state = app(HistoricalYearTemplateService::class)->readFormState(Storage::disk('local')->path($data['file']), $this->customerRecord);
                        $this->form->fill($state);

                        Notification::make()->success()->title(__('Form filled from template'))->body(__('Review the values, then save.'))->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Could not read template'))->body($e->getMessage())->send();
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }
                }),
        ];
    }

    public function getTitle(): string
    {
        return __('Historical year entry — :name', ['name' => $this->customerRecord->name]);
    }

    public function form(Form $form): Form
    {
        $statusOptions = array_combine(
            array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()),
            array_map(fn (PaymentStatus $s) => ucfirst($s->value), PaymentStatus::cases())
        );

        // $optional: a standalone payment section the admin may leave
        // entirely blank — only required once any of its fields is filled.
        $paymentFields = fn (bool $optional = false) => [
            TextInput::make('amount')->label(__('Amount'))->numeric()->prefix('€')
                ->required(fn (Get $get): bool => ! $optional || filled($get('due_date')) || filled($get('paid_amount')) || filled($get('paid_date'))),
            DatePicker::make('due_date')->label(__('Due date'))
                ->required(fn (Get $get): bool => ! $optional || filled($get('amount'))),
            Select::make('status')->label(__('Status'))->options($statusOptions)->default('pending')->native(false),
            TextInput::make('paid_amount')->label(__('Paid amount'))->numeric()->prefix('€'),
            DatePicker::make('paid_date')->label(__('Paid date')),
        ];

        return $form->schema([
            TextInput::make('year')
                ->label(__('Year'))
                ->numeric()
                ->integer()
                ->minValue(2000)
                ->maxValue(now()->year)
                ->required()
                ->helperText(fn (): ?string => ($years = $this->customerRecord->taxCalculations()->orderBy('tax_year')->pluck('tax_year'))->isNotEmpty()
                    ? __('Years already on file: :years — saving one of these overwrites it.', ['years' => $years->implode(', ')])
                    : null),

            Section::make(__('Tax'))->schema([
                TextInput::make('tax.coefficient_used')->label(__('Coefficient used (%)'))->numeric()->suffix('%')->placeholder((string) $this->customerRecord->coefficient_of_profitability),
                TextInput::make('tax.tax_rate_used')->label(__('Tax rate used (%)'))->numeric()->suffix('%')->placeholder((string) $this->customerRecord->tax_rate),
                TextInput::make('tax.total_sales')->label(__('Total sales'))->numeric()->prefix('€'),
                TextInput::make('tax.taxable_income')->label(__('Taxable income'))->numeric()->prefix('€'),
                TextInput::make('tax.tax_due')->label(__('Tax due'))->numeric()->prefix('€'),
                TextInput::make('tax.advance_payments_made')->label(__('Advance payments made'))->numeric()->prefix('€')->default(0),
                TextInput::make('tax.balance_payment')->label(__('Balance payment'))->numeric()->prefix('€'),
                TextInput::make('tax.next_year_advance_base')->label(__('Next year advance base'))->numeric()->prefix('€'),
            ])->columns(3),

            Section::make(__('Tax payments'))->schema([
                Repeater::make('tax_payments')->label(__('Payments'))->schema([
                    Select::make('payment_type')->label(__('Type'))->options(array_combine(
                        array_map(fn (TaxPaymentType $t) => $t->value, TaxPaymentType::cases()),
                        array_map(fn (TaxPaymentType $t) => $t->value, TaxPaymentType::cases())
                    ))->native(false)->required(),
                    ...$paymentFields(),
                ])->columns(3)->defaultItems(0),
            ]),

            Section::make(__('INPS'))->schema([
                TextInput::make('inps.taxable_income')->label(__('INPS taxable income'))->numeric()->prefix('€'),
                TextInput::make('inps.inps_amount')->label(__('INPS amount'))->numeric()->prefix('€'),
                TextInput::make('inps.inps_rate')->label(__('Average rate (%)'))->numeric()->suffix('%'),
                TextInput::make('inps.discount_percentage')->label(__('Discount (%)'))->numeric()->suffix('%')->default(0),
                TextInput::make('inps.discount_amount')->label(__('Discount amount'))->numeric()->prefix('€')->default(0),
                TextInput::make('inps.inps_amount_after_discount')->label(__('After discount'))->numeric()->prefix('€'),
                Select::make('inps.calculation_method')->label(__('Method'))->options(['percentage' => 'Percentage', 'minimum' => 'Minimum'])->native(false),
            ])->columns(3),

            Section::make(__('INPS payments'))->schema([
                Repeater::make('inps_payments')->label(__('Installments'))->schema([
                    TextInput::make('installment_number')->label(__('#'))->numeric()->required(),
                    ...$paymentFields(),
                ])->columns(3)->defaultItems(0),
            ]),

            Section::make(__('INAIL'))->visible(fn (): bool => $this->customerRecord->customer_type === CustomerType::Artigiani)->schema([
                TextInput::make('inail.taxable_income')->label(__('INAIL taxable income'))->numeric()->prefix('€'),
                TextInput::make('inail.inail_rate')->label(__('Rate (%)'))->numeric()->suffix('%'),
                TextInput::make('inail.inail_calculated_amount')->label(__('Calculated amount'))->numeric()->prefix('€'),
                TextInput::make('inail.inail_minimum_payment')->label(__('Minimum payment'))->numeric()->prefix('€'),
                TextInput::make('inail.inail_final_amount')->label(__('Final amount'))->numeric()->prefix('€'),
                TextInput::make('inail.ateco_code')->label(__('ATECO code')),
            ])->columns(3),

            Section::make(__('INAIL payment'))->visible(fn (): bool => $this->customerRecord->customer_type === CustomerType::Artigiani)->schema($paymentFields(true))->columns(3)->statePath('inail_payment'),

            Section::make(__('Diritto Annuale payment'))->visible(fn (): bool => in_array($this->customerRecord->customer_type, [CustomerType::Commercianti, CustomerType::Artigiani], true))->schema($paymentFields(true))->columns(3)->statePath('diritto_annuale_payment'),

            Section::make(__('Fattura Tra Privati'))->schema([
                TextInput::make('fattura_tra_privati.total_invoices')->label(__('Total invoices'))->numeric(),
                TextInput::make('fattura_tra_privati.total_sales_amount')->label(__('Total sales amount'))->numeric()->prefix('€'),
                TextInput::make('fattura_tra_privati.payment_per_invoice')->label(__('Fee per invoice'))->numeric()->prefix('€'),
                TextInput::make('fattura_tra_privati.total_payment_amount')->label(__('Total payment amount'))->numeric()->prefix('€'),
                TextInput::make('fattura_tra_privati.minimum_invoice_amount')->label(__('Qualifying threshold'))->numeric()->prefix('€'),
            ])->columns(3),

            Section::make(__('Fattura Tra Privati payment'))->schema($paymentFields(true))->columns(3)->statePath('fattura_tra_privati_payment'),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $year = (int) $state['year'];

        $data = array_filter([
            'tax' => $this->filledOrNull($state['tax'] ?? []),
            'tax_payments' => $this->filledPayments($state['tax_payments'] ?? [], $year, 'payment_type'),
            'inps' => $this->filledOrNull($state['inps'] ?? []),
            'inps_payments' => $this->filledPayments($state['inps_payments'] ?? [], $year, 'installment_number'),
            'inail' => $this->filledOrNull($state['inail'] ?? []),
            'inail_payment' => $this->filledPayment($state['inail_payment'] ?? []),
            'diritto_annuale_payment' => $this->filledPayment($state['diritto_annuale_payment'] ?? []),
            'fattura_tra_privati' => $this->filledOrNull($state['fattura_tra_privati'] ?? []),
            'fattura_tra_privati_payment' => $this->filledPayment($state['fattura_tra_privati_payment'] ?? []),
        ], fn ($v) => $v !== null && $v !== []);

        try {
            app(HistoricalYearEntryService::class)->save($this->customerRecord, $year, $data);

            Notification::make()->success()->title(__('Historical year saved'))->send();

            $this->redirect(CustomerTaxDashboard::getUrl(['customer' => $this->customerRecord->id, 'year' => $year]));
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('Could not save'))->body($e->getMessage())->send();
        }
    }

    private function filledOrNull(array $values): ?array
    {
        $values = array_filter($values, fn ($v) => $v !== null && $v !== '');

        return $values === [] ? null : $values;
    }

    /** A standalone payment counts only if an amount was entered (status alone defaults to "pending"). */
    private function filledPayment(array $values): ?array
    {
        return filled($values['amount'] ?? null) ? $this->filledOrNull($values) : null;
    }

    private function filledPayments(array $rows, int $year, string $keyField): array
    {
        return collect($rows)
            ->filter(fn (array $row) => filled($row['amount'] ?? null))
            // Drop blank optional fields so model defaults apply (paid_amount
            // is NOT NULL with a 0 default — a null would fail the insert).
            ->map(fn (array $row) => array_filter($row, fn ($v) => $v !== null && $v !== ''))
            // Tax advances are stored against the year they count toward
            // (Y+1), like PaymentScheduleService does for calculated years —
            // otherwise TaxCalculationService never subtracts them from the
            // next year's balance.
            ->map(fn (array $row) => $row + [
                'tax_year' => in_array($row[$keyField] ?? null, [TaxPaymentType::FirstAdvance->value, TaxPaymentType::SecondAdvance->value], true) ? $year + 1 : $year,
                'payment_year' => $year,
            ])
            ->values()
            ->all();
    }
}
