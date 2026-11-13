<?php

namespace App\Filament\Pages;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\IncomeImportBatch;
use App\Models\TaxCalculation;
use App\Services\Tax\Import\IncomeDataImportService;
use App\Services\Tax\PaymentCalendarService;
use App\Services\Tax\PaymentRegistry;
use App\Services\Tax\RavvedimentoService;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Services\Tax\TaxOverviewService;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
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

    // Only $customer is a mount parameter: an optional second scalar parameter
    // with a default (for $year) makes Laravel's resolveMethodDependencies()
    // splice that default into the route-params array when /tax has no year
    // segment, turning it into a mix of named + positional keys. Livewire's
    // lifecycle hooks then unpack that array with `...$params`, which PHP
    // rejects ("positional argument after named argument"). Reading $year
    // straight off the route avoids ever giving mount() an optional param.
    public function mount(int|string $customer): void
    {
        $this->customerRecord = Customer::findOrFail((int) $customer);

        $year = request()->route('year');
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

    public function getOverview(): array
    {
        return app(TaxOverviewService::class)->build($this->customerRecord, $this->selectedYear);
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
            $this->f24SheetAction(),
            $this->annualTaxReportAction(),
        ];
    }

    public static function f24SheetUrl(int $customerId, CarbonInterface $due, CarbonInterface $payOn): string
    {
        return URL::temporarySignedRoute('tax.f24-sheet.download', now()->addMinutes(30), [
            'customer' => $customerId,
            'date' => $due->format('Y-m-d'),
            'pay_on' => ($payOn->lt($due) ? $due : $payOn)->format('Y-m-d'),
        ]);
    }

    /** One F24 sheet per deadline: everything still open that is due that day. */
    public function f24SheetAction(): Action
    {
        return Action::make('f24Sheet')
            ->label(__('F24 sheet'))
            ->icon('heroicon-o-document-currency-euro')
            ->color('gray')
            ->modalDescription(__('All open payments of one deadline, line by line as they go on the F24. Paying after the deadline adds the ravvedimento interest and penalty.'))
            ->modalSubmitActionLabel(__('Download PDF'))
            ->form([
                Select::make('due_date')
                    ->label(__('Deadline'))
                    ->native(false)
                    ->required()
                    ->options(fn () => app(PaymentCalendarService::class)
                        ->entries(status: 'open', customerId: $this->customerRecord->id)
                        ->groupBy(fn ($e) => $e['due_date']->format('Y-m-d'))
                        ->sortKeys()
                        ->mapWithKeys(fn ($rows, $date) => [$date => Carbon::parse($date)->format('d/m/Y').' — € '.number_format($rows->sum('outstanding'), 2, ',', '.').($rows->first()['status'] === 'overdue' ? ' · '.__('overdue') : '')])
                        ->all()),
                DatePicker::make('pay_on')->label(__('Payment date'))->default(now()->toDateString())->required(),
            ])
            ->action(fn (array $data) => redirect()->to(self::f24SheetUrl($this->customerRecord->id, Carbon::parse($data['due_date']), Carbon::parse($data['pay_on']))));
    }

    /** What an overdue payment costs to settle today (or another day) — ravvedimento operoso. */
    public function lateCostAction(): Action
    {
        return Action::make('lateCost')
            ->label(__('Late-payment cost'))
            ->icon('heroicon-o-calculator')
            ->modalHeading(__('Late-payment cost (ravvedimento operoso)'))
            ->modalSubmitActionLabel(__('Download F24 sheet'))
            ->fillForm(fn (array $arguments) => ['type' => $arguments['type'] ?? null, 'id' => $arguments['id'] ?? null, 'pay_on' => now()->toDateString()])
            ->form([
                Hidden::make('type'),
                Hidden::make('id'),
                DatePicker::make('pay_on')->label(__('Payment date'))->required()->live(),
                Placeholder::make('breakdown')->hiddenLabel()->content(function (Get $get) {
                    $payment = $this->findPayment(['type' => $get('type'), 'id' => $get('id')]);
                    if (! $payment || ! $get('pay_on')) {
                        return null;
                    }

                    $outstanding = round((float) $payment->amount - (float) $payment->paid_amount, 2);
                    $r = app(RavvedimentoService::class)->calculate($get('type'), $outstanding, $payment->due_date, Carbon::parse($get('pay_on')));
                    $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');

                    return new HtmlString(view('filament.pages.partials.late-cost', ['r' => $r, 'outstanding' => $outstanding, 'payment' => $payment, 'eur' => $eur])->render());
                }),
            ])
            ->action(function (array $data) {
                $payment = $this->findPayment($data);
                abort_if($payment === null, 404);

                return redirect()->to(self::f24SheetUrl($this->customerRecord->id, $payment->due_date, Carbon::parse($data['pay_on'])));
            });
    }

    public function annualTaxReportAction(): Action
    {
        return Action::make('annualTaxReport')
            ->label(__('Download annual report (PDF)'))
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn () => $this->getTaxCalculation() !== null)
            ->url(fn () => URL::temporarySignedRoute('tax.annual-report.download', now()->addMinutes(30), [
                'customer' => $this->customerRecord->id,
                'year' => $this->selectedYear,
            ]))
            ->openUrlInNewTab();
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
            ->modalDescription(__('Invoices list or cash register export (CSV/Excel), e-invoices (.xml / .xml.p7m) or a ZIP of them. The format is detected automatically; already-imported rows are skipped.'))
            ->modalFooterActions(fn (Action $action) => [
                $action->getModalSubmitAction()->label(__('Import')),
                $action->getModalCancelAction(),
            ])
            ->extraModalFooterActions(fn () => [
                Action::make('openImportPage')->label(__('Import several files'))->color('gray')->link()->url(ImportIncome::getUrl()),
                Action::make('sampleInvoices')->label(__('Sample invoices list'))->icon('heroicon-m-arrow-down-tray')->color('gray')->link()
                    ->url(route('tax.import-sample', ['format' => 'invoices', 'ext' => 'csv', 'customer' => $this->customerRecord->id])),
                Action::make('sampleCashRegister')->label(__('Sample cash register'))->icon('heroicon-m-arrow-down-tray')->color('gray')->link()
                    ->url(route('tax.import-sample', ['format' => 'cash-register', 'ext' => 'csv', 'customer' => $this->customerRecord->id])),
            ])
            ->form([
                FileUpload::make('file')
                    ->label(__('File'))
                    ->disk('local')
                    ->directory(ImportIncome::UPLOAD_DIR)
                    ->visibility('private')
                    ->storeFileNamesIn('name')
                    ->maxSize(20 * 1024)
                    ->rules(['extensions:csv,txt,xls,xlsx,ods,xml,p7m,zip'])
                    ->required(),
            ])
            ->action(function (array $data) {
                $importer = app(IncomeDataImportService::class);
                $path = Storage::disk('local')->path($data['file']);
                $name = $data['name'] ?? basename($data['file']);
                $kept = false;

                try {
                    $analysis = $importer->analyze($path, $name, $this->customerRecord);
                    if ($analysis['error'] !== null) {
                        Notification::make()->danger()->title(__('Import failed'))->body($analysis['error'])->persistent()->send();

                        return;
                    }

                    $batch = $importer->commit($path, $name, $this->customerRecord, IncomeImportBatch::CHANNEL_UPLOAD, Auth::id(), $data['file']);
                    $kept = true;

                    Notification::make()->success()->title(__('Import complete'))
                        ->body(implode("\n", array_filter([
                            __(':imported imported, :duplicates duplicates skipped, :failed failed.', ['imported' => $batch->imported, 'duplicates' => $batch->duplicates, 'failed' => $batch->failed]),
                            $batch->recalculated_years ? __('Recalculated: :years.', ['years' => implode(', ', $batch->recalculated_years)]) : null,
                            ...($batch->warnings ?? []),
                        ])))
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('Import failed'))->body($e->getMessage())->send();
                } finally {
                    if (! $kept) {
                        Storage::disk('local')->delete($data['file']);
                    }
                }
            });
    }

    // ── Row actions (mark paid / upload receipt), parameterized by payment type ──

    private function findPayment(array $arguments): ?Model
    {
        return PaymentRegistry::find((string) ($arguments['type'] ?? ''), (int) ($arguments['id'] ?? 0), $this->customerRecord->id);
    }

    public function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as paid'))
            ->icon('heroicon-o-check-circle')
            ->fillForm(function (array $arguments): array {
                $payment = $this->findPayment($arguments);

                // Confirming a payment the customer reported: start from what they sent.
                $reported = $payment?->reported_paid_at && $payment->status !== PaymentStatus::Paid;

                return [
                    'status' => $reported ? PaymentStatus::Paid->value : ($payment?->status?->value ?? PaymentStatus::Paid->value),
                    'paid_amount' => $reported ? $payment->reported_paid_amount : ($payment?->paid_amount ?? $payment?->amount),
                    'paid_date' => $reported ? $payment->reported_paid_date : ($payment?->paid_date ?? now()->toDateString()),
                ];
            })
            ->form([
                Select::make('status')
                    ->label(__('Status'))
                    ->options(array_combine(array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()), array_map(fn (PaymentStatus $s) => ucfirst($s->value), PaymentStatus::cases())))
                    ->native(false)
                    ->required(),
                TextInput::make('paid_amount')->label(__('Paid amount'))->numeric()->prefix('€')->required(),
                DatePicker::make('paid_date')->label(__('Paid date')),
            ])
            ->action(function (array $arguments, array $data) {
                $payment = $this->findPayment($arguments);
                $payment?->update($data);

                Notification::make()->success()->title(__('Payment updated'))->send();
            });
    }

    /**
     * Full edit of a scheduled payment (amount and due date too, not just
     * the paid fields) — VTMS's Tax_calculations::editPayment(). Note that
     * "Recalculate" re-derives amount/due_date from the calculation, so a
     * manual override only survives until the next recalculation.
     */
    public function editPaymentAction(): Action
    {
        return Action::make('editPayment')
            ->label(__('Edit payment'))
            ->icon('heroicon-o-pencil-square')
            ->fillForm(function (array $arguments): array {
                $payment = $this->findPayment($arguments);

                return [
                    'amount' => $payment?->amount,
                    'due_date' => $payment?->due_date?->toDateString(),
                    'status' => $payment?->status?->value ?? PaymentStatus::Pending->value,
                    'paid_amount' => $payment?->paid_amount ?? 0,
                    'paid_date' => $payment?->paid_date?->toDateString(),
                ];
            })
            ->form([
                TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0)->prefix('€')->required(),
                DatePicker::make('due_date')->label(__('Due date'))->required(),
                Select::make('status')
                    ->label(__('Status'))
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $s) => [$s->value => ucfirst($s->value)]))
                    ->native(false)
                    ->required(),
                TextInput::make('paid_amount')->label(__('Paid amount'))->numeric()->minValue(0)->prefix('€')->default(0),
                DatePicker::make('paid_date')->label(__('Paid date')),
            ])
            ->action(function (array $arguments, array $data) {
                $payment = $this->findPayment($arguments);

                if (! $payment) {
                    Notification::make()->danger()->title(__('Payment not found'))->send();

                    return;
                }

                $data['paid_amount'] = $data['paid_amount'] ?? 0;
                $payment->update($data);

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
                $payment = $this->findPayment($arguments);
                $payment?->update(['uploaded_pdf' => $data['uploaded_pdf']]);

                Notification::make()->success()->title(__('Receipt uploaded'))->send();
            });
    }
}
