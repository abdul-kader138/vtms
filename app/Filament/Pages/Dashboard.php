<?php

namespace App\Filament\Pages;

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
        return [];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
