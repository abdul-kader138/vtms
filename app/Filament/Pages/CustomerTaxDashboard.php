<?php

namespace App\Filament\Pages;

use App\Enums\IncomeSource;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\DirittoAnnualePayment;
use App\Models\FatturaTraPrivatiCalculation;
use App\Models\FatturaTraPrivatiPayment;
use App\Models\InailCalculation;
use App\Models\InailPayment;
use App\Models\IncomeData;
use App\Models\InpsCalculation;
use App\Models\InpsPayment;
use App\Models\TaxCalculation;
use App\Models\TaxPayment;
use App\Services\Tax\Import\IncomeDataImportService;
use App\Services\Tax\Import\PrivatiDeviceImportParser;
use App\Services\Tax\Import\SdiFatturaImportParser;
use App\Services\Tax\TaxCalculationOrchestrator;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One screen per customer/year showing all 5 tax-domain calculations and
 * their payment schedules at once — matches VTMS's actual UX (Tax_calculations::view()),
 * rather than splitting each domain into its own Resource relation-manager tab.
 */
class CustomerTaxDashboard extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.customer-tax-dashboard';

    protected static bool $shouldRegisterNavigation = false;

    // Not named $customer: Livewire's test harness pre-hydrates any public
    // property whose name matches a params array key, before mount() runs —
    // a raw int would clobber this typed Customer property in tests.
    public Customer $customerRecord;

    public int $selectedYear;

    public function mount(int|string $customer, int|string|null $year = null): void
    {
        $this->customerRecord = Customer::findOrFail((int) $customer);
        $this->selectedYear = $year !== null ? (int) $year : now()->year;
    }

    public function getTitle(): string
    {
        return __('Tax dashboard — :name', ['name' => $this->customerRecord->name]);
    }

    public static function getRoutePath(): string
    {
        return '/customers/{customer}/tax/{year?}';
    }

    public function getSubheading(): string
    {
        return __('Year :year', ['year' => $this->selectedYear]);
    }

    // ── Data for the view ──────────────────────────────────────────────────

    public function getTaxCalculation(): ?TaxCalculation
    {
        return TaxCalculation::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function getTaxPayments(): \Illuminate\Support\Collection
    {
        return TaxPayment::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->orderBy('due_date')->get();
    }

    public function getInpsCalculation(): ?InpsCalculation
    {
        return InpsCalculation::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function getInpsPayments(): \Illuminate\Support\Collection
    {
        return InpsPayment::where('customer_id', $this->customerRecord->id)->where('payment_year', $this->selectedYear)->orderBy('installment_number')->get();
    }

    public function getInailCalculation(): ?InailCalculation
    {
        return InailCalculation::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function getInailPayment(): ?InailPayment
    {
        return InailPayment::where('customer_id', $this->customerRecord->id)->where('payment_year', $this->selectedYear)->first();
    }

    public function getDirittoAnnualePayment(): ?DirittoAnnualePayment
    {
        return DirittoAnnualePayment::where('customer_id', $this->customerRecord->id)->where('payment_year', $this->selectedYear)->first();
    }

    public function getFatturaCalculation(): ?FatturaTraPrivatiCalculation
    {
        return FatturaTraPrivatiCalculation::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function getFatturaPayment(): ?FatturaTraPrivatiPayment
    {
        return FatturaTraPrivatiPayment::where('customer_id', $this->customerRecord->id)->where('payment_year', $this->selectedYear)->first();
    }

    public function previousYearUrl(): string
    {
        return static::getUrl(['customer' => $this->customerRecord->id, 'year' => $this->selectedYear - 1]);
    }

    public function nextYearUrl(): string
    {
        return static::getUrl(['customer' => $this->customerRecord->id, 'year' => $this->selectedYear + 1]);
    }

    public function historicalYearUrl(): string
    {
        return HistoricalYearEntry::getUrl(['customer' => $this->customerRecord->id]);
    }

    // ── Header actions ──────────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            $this->recalculateAction(),
            $this->importIncomeDataAction(),
        ];
    }

    public function recalculateAction(): Action
    {
        return Action::make('recalculate')
            ->label(__('Recalculate this year'))
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->action(function () {
                try {
                    app(TaxCalculationOrchestrator::class)->runForCustomerAndYear($this->customerRecord, $this->selectedYear);

                    Notification::make()->success()->title(__('Recalculated'))->body(__('Tax figures for :year have been recalculated.', ['year' => $this->selectedYear]))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('Recalculation failed'))->body($e->getMessage())->send();
                }
            });
    }

    public function importIncomeDataAction(): Action
    {
        return Action::make('importIncomeData')
            ->label(__('Import income data'))
            ->icon('heroicon-o-arrow-up-tray')
            ->form([
                Select::make('source')
                    ->label(__('Source format'))
                    ->options([
                        IncomeSource::SdiFattura->value => 'SDI fattura',
                        IncomeSource::PrivatiDevice->value => 'Privati/device',
                    ])
                    ->native(false)
                    ->required(),
                FileUpload::make('file')
                    ->label(__('CSV/XLS file'))
                    ->disk('local')
                    ->directory('tax-imports')
                    ->visibility('private')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->required(),
            ])
            ->action(function (array $data) {
                $source = IncomeSource::from($data['source']);
                $path = Storage::disk('local')->path($data['file']);

                $parser = match ($source) {
                    IncomeSource::SdiFattura => app(SdiFatturaImportParser::class),
                    IncomeSource::PrivatiDevice => app(PrivatiDeviceImportParser::class),
                };

                try {
                    $rows = $parser->parse($path, $this->customerRecord);
                    $result = app(IncomeDataImportService::class)->import($rows, $this->customerRecord, Auth::id());

                    Notification::make()->success()->title(__('Import complete'))
                        ->body(__(':imported imported, :duplicates duplicates skipped, :failed failed.', $result))
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('Import failed'))->body($e->getMessage())->send();
                } finally {
                    Storage::disk('local')->delete($data['file']);
                }
            });
    }

    // ── Row actions (mark paid / upload receipt), parameterized by payment type ──

    private function paymentModelClass(string $type): string
    {
        return match ($type) {
            'tax' => TaxPayment::class,
            'inps' => InpsPayment::class,
            'inail' => InailPayment::class,
            'diritto_annuale' => DirittoAnnualePayment::class,
            'fattura_tra_privati' => FatturaTraPrivatiPayment::class,
            default => throw new AuthorizationException('Unknown payment type.'),
        };
    }

    public function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as paid'))
            ->icon('heroicon-o-check-circle')
            ->fillForm(function (array $arguments): array {
                $payment = $this->paymentModelClass($arguments['type'])::find($arguments['id']);

                return [
                    'status' => $payment?->status?->value ?? PaymentStatus::Paid->value,
                    'paid_amount' => $payment?->paid_amount ?? $payment?->amount,
                    'paid_date' => $payment?->paid_date ?? now()->toDateString(),
                ];
            })
            ->form([
                Select::make('status')
                    ->label(__('Status'))
                    ->options(array_combine(array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()), array_map(fn (PaymentStatus $s) => ucfirst($s->value), PaymentStatus::cases())))
                    ->native(false)
                    ->required(),
                \Filament\Forms\Components\TextInput::make('paid_amount')->label(__('Paid amount'))->numeric()->prefix('€')->required(),
                DatePicker::make('paid_date')->label(__('Paid date')),
            ])
            ->action(function (array $arguments, array $data) {
                $payment = $this->paymentModelClass($arguments['type'])::find($arguments['id']);
                $payment?->update($data);

                Notification::make()->success()->title(__('Payment updated'))->send();
            });
    }

    public function uploadReceiptAction(): Action
    {
        return Action::make('uploadReceipt')
            ->label(__('Upload receipt'))
            ->icon('heroicon-o-paper-clip')
            ->form([
                // Private disk: these are financial receipts, not public
                // assets like avatars/branding — see TaxPaymentReceiptController
                // for the only (signed, staff-gated) way to read one back.
                FileUpload::make('uploaded_pdf')
                    ->label(__('Receipt PDF'))
                    ->disk('local')
                    ->directory('tax-receipts')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])
                    ->required(),
            ])
            ->action(function (array $arguments, array $data) {
                $payment = $this->paymentModelClass($arguments['type'])::find($arguments['id']);
                $payment?->update(['uploaded_pdf' => $data['uploaded_pdf']]);

                Notification::make()->success()->title(__('Receipt uploaded'))->send();
            });
    }
}
