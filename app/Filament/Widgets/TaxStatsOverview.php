<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\PaymentCalendar;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Tax\PaymentCalendarService;
use App\Support\Tax\TaxRules;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TaxStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $calendar = app(PaymentCalendarService::class);
        $open = $calendar->entries(status: 'open');
        $overdue = $open->where('status', 'overdue');
        $next30 = $open->where('status', 'pending')->filter(fn ($e) => $e['due_date']->lte(today()->addDays(30)));
        $eur = fn (float $v) => '€ '.number_format($v, 2, ',', '.');

        return [
            Stat::make(__('Customers'), Customer::count())
                ->description(TaxRules::isLaw() ? __('Italian law rules') : __('VTMS legacy rules'))
                ->descriptionIcon('heroicon-m-scale')
                ->url(CustomerResource::getUrl()),
            Stat::make(__('Overdue'), $eur($overdue->sum('outstanding')))
                ->description(trans_choice(':count payment past its due date|:count payments past their due date', $overdue->count()))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($overdue->isNotEmpty() ? 'danger' : 'gray')
                ->url(PaymentCalendar::getUrl(['status' => 'overdue'])),
            Stat::make(__('Due in the next 30 days'), $eur($next30->sum('outstanding')))
                ->description(trans_choice(':count payment|:count payments', $next30->count()))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($next30->isNotEmpty() ? 'warning' : 'gray')
                ->url(PaymentCalendar::getUrl()),
            Stat::make(__('Total outstanding'), $eur($open->sum('outstanding')))
                ->description(__('All unpaid scheduled payments'))
                ->descriptionIcon('heroicon-m-banknotes'),
        ];
    }
}
