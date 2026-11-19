<?php

namespace App\Filament\Pages;

use App\Enums\PaymentStatus;
use App\Enums\TaxAdjustmentRequestStatus;
use App\Exceptions\Tax\ManualOverrideApprovalRequiredException;
use App\Models\Customer;
use App\Models\FatturaTraPrivatiCalculation;
use App\Models\InailCalculation;
use App\Models\IncomeImportBatch;
use App\Models\InpsCalculation;
use App\Models\TaxAdjustmentRequest;
use App\Models\TaxCalculation;
use App\Models\TaxYearLock;
use App\Services\Tax\Import\IncomeDataImportService;
use App\Services\Tax\IncomeClosingService;
use App\Services\Tax\IncomePredictionService;
use App\Services\Tax\PaymentCalendarService;
use App\Services\Tax\PaymentRegistry;
use App\Services\Tax\RavvedimentoService;
use App\Services\Tax\TaxCalculationOrchestrator;
use App\Services\Tax\TaxOverviewService;
use App\Services\Tax\TaxYearDeletionService;
use App\Support\Tax\TaxRules;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Actions\Action as NotificationAction;
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

    public function getInpsCalculation(): ?InpsCalculation
    {
        return InpsCalculation::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function getInailCalculation(): ?InailCalculation
    {
        return InailCalculation::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function getFatturaCalculation(): ?FatturaTraPrivatiCalculation
    {
        return FatturaTraPrivatiCalculation::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function getYearLock(): ?TaxYearLock
    {
        return TaxYearLock::where('customer_id', $this->customerRecord->id)->where('tax_year', $this->selectedYear)->first();
    }

    public function isYearLocked(): bool
    {
        return $this->getYearLock() !== null;
    }

    /** Shown as helper text under a review-modal field so staff can eyeball a swing before saving — see plan item #5. */
    private function previousValueHelper(?Model $calculation, string $field): ?string
    {
        if (! $calculation || $calculation->{$field} === null) {
            return null;
        }

        return __('Previously: € :value', ['value' => number_format((float) $calculation->{$field}, 2, ',', '.')]);
    }

    public function getOverview(): array
    {
        return app(TaxOverviewService::class)->build($this->customerRecord, $this->selectedYear);
    }

    /**
     * Entirely separate from getOverview() above — a representative,
     * year-over-year estimate (IncomePredictionService::predictRepresentative()),
     * not an official figure, and never affects any real tax/INPS
     * calculation. Rendered in its own section on the page, not merged
     * into the real month-by-month data anywhere.
     */
    public function getPrediction(): array
    {
        return [
            'closed_months' => app(IncomeClosingService::class)->closedMonths($this->customerRecord, $this->selectedYear),
            'prediction' => app(IncomePredictionService::class)->predictRepresentative($this->customerRecord, $this->selectedYear),
        ];
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

    // Seven actions in a flat row is action soup: only the two actions used
    // on nearly every visit (recalculate, import) stay as standalone
    // buttons. Documents and the rarer/destructive actions are grouped into
    // dropdowns, keeping the header scannable at a glance.
    protected function getHeaderActions(): array
    {
        return [
            $this->recalculateAction(),
            $this->importIncomeDataAction(),
            ActionGroup::make([
                $this->f24SheetAction(),
                $this->annualTaxReportAction(),
                $this->redditiReportAction(),
            ])
                ->label(__('Documents'))
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->button(),
            ActionGroup::make([
                $this->recalculateForwardAction(),
                $this->lockYearAction(),
                $this->unlockYearAction(),
                $this->deleteYearAction(),
            ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->iconButton()
                ->tooltip(__('More actions')),
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
                DatePicker::make('pay_on')->label(__('Payment date'))->native(false)->displayFormat('d/m/Y')->default(now()->toDateString())->required(),
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
                DatePicker::make('pay_on')->label(__('Payment date'))->native(false)->displayFormat('d/m/Y')->required()->live(),
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

    public function redditiReportAction(): Action
    {
        return Action::make('redditiReport')
            ->label(__('Redditi PF draft (Quadro LM/RR)'))
            ->icon('heroicon-o-document-chart-bar')
            ->color('gray')
            ->visible(fn () => $this->getTaxCalculation() !== null)
            ->url(fn () => URL::temporarySignedRoute('tax.redditi-report.download', now()->addMinutes(30), [
                'customer' => $this->customerRecord->id,
                'year' => $this->selectedYear,
            ]))
            ->openUrlInNewTab();
    }

    /**
     * Opens with every figure the 4 calculators would produce right now,
     * already filled in via preview() — nothing is written yet. Staff can
     * adjust any value (INPS discount, an installment split, a rounding
     * edge case) before confirming; TaxCalculationOrchestrator::commit()
     * then persists the edited figures and flags them is_manually_adjusted.
     */
    public function recalculateAction(): Action
    {
        return Action::make('recalculate')
            ->label(__('Recalculate this year'))
            ->icon('heroicon-o-arrow-path')
            ->disabled(fn () => $this->isYearLocked())
            ->tooltip(fn () => $this->isYearLocked() ? __('This year is locked — unlock it first.') : null)
            ->modalHeading(__('Review figures for :year', ['year' => $this->selectedYear]))
            ->modalDescription(__('These are the freshly computed figures — adjust anything before saving. Paid payments are never rewritten. Changing a figure requires a reason, and saving it requires the finalize-tax-calculation permission.'))
            ->modalSubmitActionLabel(__('Save'))
            ->modalWidth('4xl')
            ->fillForm(fn () => app(TaxCalculationOrchestrator::class)->preview($this->customerRecord, $this->selectedYear))
            ->form([
                Section::make(__('Income tax (Regime Forfettario)'))
                    ->schema([
                        TextInput::make('tax.taxable_income')->label(__('Taxable income'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getTaxCalculation(), 'taxable_income')),
                        TextInput::make('tax.tax_due')->label(__('Tax due'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getTaxCalculation(), 'tax_due')),
                        TextInput::make('tax.advance_payments_made')->label(__('Advances already made'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getTaxCalculation(), 'advance_payments_made')),
                        TextInput::make('tax.balance_payment')->label(__('Balance payment'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getTaxCalculation(), 'balance_payment')),
                        Textarea::make('tax.adjustment_reason')->label(__('Reason for changes'))->rows(2)->columnSpanFull()
                            ->helperText(__('Required only if a figure above was changed from its computed value.')),
                    ])
                    ->columns(2),
                Section::make(__('INPS'))
                    ->schema([
                        TextInput::make('inps.inps_amount')->label(__('INPS amount'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getInpsCalculation(), 'inps_amount')),
                        TextInput::make('inps.discount_percentage')->label(__('Discount %'))->numeric()->suffix('%')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getInpsCalculation(), 'discount_percentage')),
                        TextInput::make('inps.discount_amount')->label(__('Discount amount'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getInpsCalculation(), 'discount_amount')),
                        TextInput::make('inps.inps_amount_after_discount')->label(__('Amount after discount'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getInpsCalculation(), 'inps_amount_after_discount')),
                        Textarea::make('inps.adjustment_reason')->label(__('Reason for changes'))->rows(2)->columnSpanFull()
                            ->helperText(__('Required only if a figure above was changed from its computed value.')),
                    ])
                    ->columns(2),
                Section::make(__('INAIL'))
                    ->visible(fn (Get $get) => $get('inail.inail_final_amount') !== null)
                    ->schema([
                        TextInput::make('inail.inail_calculated_amount')->label(__('Calculated amount'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getInailCalculation(), 'inail_calculated_amount')),
                        TextInput::make('inail.inail_final_amount')->label(__('Final amount (after minimum)'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getInailCalculation(), 'inail_final_amount')),
                        Textarea::make('inail.adjustment_reason')->label(__('Reason for changes'))->rows(2)->columnSpanFull()
                            ->helperText(__('Required only if a figure above was changed from its computed value.')),
                    ])
                    ->columns(2),
                Section::make(__('Imposta di bollo (fattura tra privati)'))
                    ->visible(fn (Get $get) => $get('fattura_tra_privati.total_payment_amount') !== null)
                    ->schema([
                        TextInput::make('fattura_tra_privati.total_payment_amount')->label(__('Total stamp duty'))->numeric()->prefix('€')->required()
                            ->helperText(fn () => $this->previousValueHelper($this->getFatturaCalculation(), 'total_payment_amount')),
                        Textarea::make('fattura_tra_privati.adjustment_reason')->label(__('Reason for changes'))->rows(2)
                            ->helperText(__('Required only if the figure above was changed from its computed value.')),
                    ]),
            ])
            ->action(function (array $data) {
                $orchestrator = app(TaxCalculationOrchestrator::class);

                if ($mismatch = $orchestrator->ruleMismatch($this->customerRecord, $this->selectedYear)) {
                    $this->notifyRuleMismatch($mismatch);

                    return;
                }

                try {
                    $result = $orchestrator->commit($this->customerRecord, $this->selectedYear, $data);

                    Notification::make()->success()->title(__('Recalculated'))->body(__('Tax figures for :year have been recalculated.', ['year' => $this->selectedYear]))->send();

                    if ($result['variance_warnings'] !== []) {
                        Notification::make()->warning()->persistent()
                            ->title(__('Large manual adjustment(s) — please double-check'))
                            ->body(implode("\n", $result['variance_warnings']))
                            ->send();
                    }
                } catch (ManualOverrideApprovalRequiredException) {
                    TaxAdjustmentRequest::create([
                        'customer_id' => $this->customerRecord->id,
                        'tax_year' => $this->selectedYear,
                        'payload' => $data,
                        'status' => TaxAdjustmentRequestStatus::Pending,
                        'requested_by' => Auth::id(),
                        'requested_at' => now(),
                    ]);

                    Notification::make()->warning()->persistent()
                        ->title(__('Submitted for review'))
                        ->body(__('You don\'t have permission to finalize a manual adjustment, so nothing was saved yet — it has been queued under Tax Adjustment Requests for someone with that permission to approve.'))
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('Recalculation failed'))->body($e->getMessage())->send();
                }
            });
    }

    /** Blocks any further recalculation of this year (manual or forward-walked) until unlockYearAction() is used. */
    public function lockYearAction(): Action
    {
        return Action::make('lockYear')
            ->label(__('Lock this year'))
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->visible(fn () => ! $this->isYearLocked() && (Auth::user()?->can('finalize_tax_calculation') ?? false))
            ->modalDescription(__('Locking :year prevents any further recalculation of it — including "recalculate forward" from an earlier year — until it is unlocked again.', ['year' => $this->selectedYear]))
            ->form([
                Textarea::make('reason')->label(__('Reason (optional)'))->rows(2),
            ])
            ->action(function (array $data) {
                TaxYearLock::updateOrCreate(
                    ['customer_id' => $this->customerRecord->id, 'tax_year' => $this->selectedYear],
                    ['locked_by' => Auth::id(), 'locked_at' => now(), 'reason' => $data['reason'] ?? null],
                );

                Notification::make()->success()->title(__('Year locked'))->body(__(':year is now locked against recalculation.', ['year' => $this->selectedYear]))->send();
            });
    }

    public function unlockYearAction(): Action
    {
        return Action::make('unlockYear')
            ->label(__('Unlock this year'))
            ->icon('heroicon-o-lock-open')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn () => $this->isYearLocked() && (Auth::user()?->can('finalize_tax_calculation') ?? false))
            ->modalDescription(__('Allows :year to be recalculated again.', ['year' => $this->selectedYear]))
            ->action(function () {
                $this->getYearLock()?->delete();

                Notification::make()->success()->title(__('Year unlocked'))->send();
            });
    }

    /**
     * TaxRules is a single global switch, not per customer — see
     * TaxCalculationOrchestrator::ruleMismatch(). Recalculating a year
     * whose existing figures were computed under a different rule set
     * would silently overwrite them with a different methodology, so both
     * recalculate actions refuse and point at Tax Settings instead.
     */
    private function notifyRuleMismatch(string $storedRules): void
    {
        Notification::make()->danger()->persistent()
            ->title(__('Rule set mismatch — not recalculated'))
            ->body(__('This customer\'s figures were last computed under ":stored" rules, but the app is currently set to ":current" (Admin → Tax Settings). Recalculating now would silently switch methodology and overwrite real numbers. Switch the setting to ":stored" first if that\'s really what you want.', [
                'stored' => TaxRules::options()[$storedRules] ?? $storedRules,
                'current' => TaxRules::options()[TaxRules::mode()] ?? TaxRules::mode(),
            ]))
            ->send();
    }

    /**
     * Walks this year and every following year that already has data,
     * recalculating each in turn — see
     * TaxCalculationOrchestrator::runForwardFromYear(). Use after editing
     * or deleting an earlier year, since one year's advances/excess-income
     * installments are scheduled into the next, so a change can ripple
     * past year + 1.
     */
    public function recalculateForwardAction(): Action
    {
        return Action::make('recalculateForward')
            ->label(__('Recalculate this year forward'))
            ->icon('heroicon-o-forward')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('Recalculates :year and every following year that already has a calculation, stopping at the first year with none. Paid payments are never rewritten — only the calculation itself and still-pending payments.', ['year' => $this->selectedYear]))
            ->action(function () {
                $orchestrator = app(TaxCalculationOrchestrator::class);

                if ($mismatch = $orchestrator->ruleMismatch($this->customerRecord, $this->selectedYear)) {
                    $this->notifyRuleMismatch($mismatch);

                    return;
                }

                try {
                    $results = $orchestrator->runForwardFromYear($this->customerRecord, $this->selectedYear);

                    if ($results === []) {
                        Notification::make()->warning()->title(__('Nothing to recalculate'))->body(__('No calculation exists for :year or later.', ['year' => $this->selectedYear]))->send();

                        return;
                    }

                    $changedYears = collect($results)->where('changed', true)->pluck('year')->implode(', ');

                    Notification::make()->success()->title(__('Recalculated forward'))
                        ->body($changedYears === ''
                            ? __('Walked :count year(s) from :year — nothing actually changed.', ['count' => count($results), 'year' => $this->selectedYear])
                            : __('Walked :count year(s) from :year — figures changed for: :years.', ['count' => count($results), 'year' => $this->selectedYear, 'years' => $changedYears]))
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('Recalculation failed'))->body($e->getMessage())->send();
                }
            });
    }

    /**
     * Shows exactly what would be removed (and why it's blocked, if it is)
     * before deleting — see TaxYearDeletionService's docblock for why a
     * plain delete of a tax year is unsafe for Artigiani/Commercianti:
     * payments it scheduled into the NEXT year cascade with it.
     */
    public function deleteYearAction(): Action
    {
        return Action::make('deleteYear')
            ->label(__('Delete this year'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn () => app(TaxYearDeletionService::class)->preview($this->customerRecord, $this->selectedYear)['has_data'])
            ->disabled(fn () => $this->isYearLocked())
            ->tooltip(fn () => $this->isYearLocked() ? __('This year is locked — unlock it first.') : null)
            ->modalHeading(fn () => __('Delete :year?', ['year' => $this->selectedYear]))
            ->modalDescription(__('This removes the calculation and every payment it scheduled — including, for Artigiani/Commercianti, advances and excess-income installments it scheduled into next year.'))
            ->modalSubmitActionLabel(__('Delete this year'))
            ->form([
                Placeholder::make('preview')
                    ->hiddenLabel()
                    ->content(fn () => new HtmlString(view('filament.pages.partials.delete-year-preview', [
                        'preview' => app(TaxYearDeletionService::class)->preview($this->customerRecord, $this->selectedYear),
                        'year' => $this->selectedYear,
                    ])->render())),
            ])
            ->action(function () {
                $service = app(TaxYearDeletionService::class);
                $year = $this->selectedYear;

                try {
                    $service->delete($this->customerRecord, $year);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('Could not delete :year', ['year' => $year]))->body($e->getMessage())->persistent()->send();

                    return;
                }

                $notification = Notification::make()->success()->title(__('Deleted'))->body(__(':year has been deleted.', ['year' => $year]));

                if ($service->staleFollowingYear($this->customerRecord, $year)) {
                    $notification->persistent()->body(__(':year has been deleted. :next still has figures computed using data from :year — recalculate it.', ['year' => $year, 'next' => $year + 1]))
                        ->actions([
                            NotificationAction::make('recalculateNext')
                                ->label(__('Recalculate :year', ['year' => $year + 1]))
                                ->url(static::getUrl(['customer' => $this->customerRecord->id, 'year' => $year + 1])),
                        ]);
                }

                $notification->send();
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
                DatePicker::make('paid_date')->label(__('Paid date'))->native(false)->displayFormat('d/m/Y'),
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
                DatePicker::make('due_date')->label(__('Due date'))->native(false)->displayFormat('d/m/Y')->required(),
                Select::make('status')
                    ->label(__('Status'))
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $s) => [$s->value => ucfirst($s->value)]))
                    ->native(false)
                    ->required(),
                TextInput::make('paid_amount')->label(__('Paid amount'))->numeric()->minValue(0)->prefix('€')->default(0),
                DatePicker::make('paid_date')->label(__('Paid date'))->native(false)->displayFormat('d/m/Y'),
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
