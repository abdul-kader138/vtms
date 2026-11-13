<?php

namespace App\Filament\Widgets;

use App\Services\Tax\PaymentCalendarService;
use Filament\Widgets\Widget;

class UpcomingDeadlines extends Widget
{
    protected static ?int $sort = 2;

    protected static string $view = 'filament.widgets.upcoming-deadlines';

    protected int|string|array $columnSpan = 1;

    public function getEntries()
    {
        $calendar = app(PaymentCalendarService::class);

        // Overdue first, then the next 45 days.
        return $calendar->entries(status: 'overdue')
            ->concat($calendar->entries(today(), today()->addDays(45), status: 'pending'))
            ->take(10);
    }
}
