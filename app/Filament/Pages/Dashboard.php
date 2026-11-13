<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PaymentsDueChart;
use App\Filament\Widgets\RevenueLimitWatch;
use App\Filament\Widgets\TaxStatsOverview;
use App\Filament\Widgets\UpcomingDeadlines;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /** Staff-only admin dashboard. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && ($user->hasRole('super_admin') || $user->getAllPermissions()->isNotEmpty());
    }

    public function getWidgets(): array
    {
        return [
            TaxStatsOverview::class,
            UpcomingDeadlines::class,
            RevenueLimitWatch::class,
            PaymentsDueChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
