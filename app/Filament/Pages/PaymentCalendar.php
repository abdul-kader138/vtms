<?php

namespace App\Filament\Pages;

use App\Enums\PaymentStatus;
use App\Services\Tax\PaymentCalendarService;
use App\Services\Tax\PaymentRegistry;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every scheduled payment across all customers in one date-ordered list —
 * the accountant's "scadenzario".
 */
class PaymentCalendar extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'filament.pages.payment-calendar';

    #[Url]
    public string $period = 'next90';

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    public static function getNavigationGroup(): ?string
    {
        return __('Tax Calculations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Payment calendar');
    }

    public function getTitle(): string
    {
        return __('Payment calendar');
    }

    public function getSubheading(): ?string
    {
        return __('All scheduled payments for every customer, with their F24 codes.');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(PaymentCalendarService::class)->entries(status: 'overdue')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Overdue payments');
    }

    public function periodOptions(): array
    {
        return [
            'next30' => __('Next 30 days'),
            'next90' => __('Next 3 months'),
            'this_month' => __('This month'),
            'this_year' => __('This year (:y)', ['y' => now()->year]),
            'next_year' => __('Next year (:y)', ['y' => now()->year + 1]),
            'last_year' => __('Last year (:y)', ['y' => now()->year - 1]),
            'all' => __('All dates'),
        ];
    }

    public function statusOptions(): array
    {
        return [
            'open' => __('Unpaid (upcoming + overdue)'),
            'overdue' => __('Overdue only'),
            'pending' => __('Upcoming only'),
            'paid' => __('Paid'),
            '' => __('Any status'),
        ];
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(): array
    {
        $today = today();

        return match ($this->period) {
            'next30' => [null, $today->copy()->addDays(30)],
            'next90' => [null, $today->copy()->addMonths(3)],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'this_year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            'next_year' => [$today->copy()->addYear()->startOfYear(), $today->copy()->addYear()->endOfYear()],
            'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
            default => [null, null],
        };
    }

    public function getEntries(): Collection
    {
        [$from, $to] = $this->range();

        // "Next N days" windows still show everything already overdue, but
        // not old payments that were settled long ago.
        if (in_array($this->period, ['next30', 'next90'], true) && ! in_array($this->status, ['open', 'overdue'], true)) {
            $from = today();
        }

        return app(PaymentCalendarService::class)->entries(
            $from,
            $to,
            $this->type !== '' ? [$this->type] : null,
            $this->status !== '' ? $this->status : null,
            null,
            trim($this->search) !== '' ? trim($this->search) : null,
        );
    }

    public function getTotals(Collection $entries): array
    {
        return app(PaymentCalendarService::class)->totals($entries);
    }

    public function resetFilters(): void
    {
        $this->reset(['period', 'status', 'type', 'search']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label(__('Export CSV'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $entries = $this->getEntries();

        return response()->streamDownload(function () use ($entries) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Due date', 'Customer', 'VAT number', 'Payment', 'F24 code', 'Amount', 'Paid', 'Outstanding', 'Status']);
            foreach ($entries as $e) {
                fputcsv($out, [
                    $e['due_date']->toDateString(),
                    $e['customer_name'],
                    $e['customer']->vat_no,
                    $e['description'],
                    $e['f24'],
                    number_format($e['amount'], 2, '.', ''),
                    number_format($e['paid_amount'], 2, '.', ''),
                    number_format($e['outstanding'], 2, '.', ''),
                    $e['status'],
                ]);
            }
            fclose($out);
        }, 'payment-calendar-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label(__('Record payment'))
            ->icon('heroicon-o-check-circle')
            ->modalHeading(fn (array $arguments) => ($p = PaymentRegistry::find($arguments['type'] ?? '', (int) ($arguments['id'] ?? 0)))
                ? PaymentRegistry::describe($arguments['type'], $p).' — '.($p->customer?->company ?: $p->customer?->name)
                : __('Record payment'))
            ->fillForm(function (array $arguments): array {
                $p = PaymentRegistry::find($arguments['type'] ?? '', (int) ($arguments['id'] ?? 0));

                return ['paid_amount' => $p?->amount, 'paid_date' => now()->toDateString()];
            })
            ->form([
                TextInput::make('paid_amount')->label(__('Amount paid'))->numeric()->minValue(0)->prefix('€')->required(),
                DatePicker::make('paid_date')->label(__('Paid on'))->required()->native(false)->displayFormat('d/m/Y'),
            ])
            ->action(function (array $arguments, array $data) {
                $p = PaymentRegistry::find($arguments['type'] ?? '', (int) ($arguments['id'] ?? 0));

                if (! $p) {
                    Notification::make()->danger()->title(__('Payment not found'))->send();

                    return;
                }

                $paid = (float) $data['paid_amount'];
                $p->update([
                    'paid_amount' => $paid,
                    'paid_date' => $data['paid_date'],
                    'status' => $paid >= (float) $p->amount ? PaymentStatus::Paid : $p->status,
                ]);

                Notification::make()->success()->title(__('Payment recorded'))->send();
            });
    }
}
