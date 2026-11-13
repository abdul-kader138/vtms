<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\IncomeData;
use App\Services\Tax\IncomePredictionService;
use Filament\Widgets\Widget;

/**
 * Customers whose revenue this year — actual or projected — is at 80% or
 * more of their Regime Forfettario limit.
 */
class RevenueLimitWatch extends Widget
{
    protected static ?int $sort = 3;

    protected static string $view = 'filament.widgets.revenue-limit-watch';

    protected int|string|array $columnSpan = 1;

    public function getRows(): array
    {
        $year = now()->year;
        $prediction = app(IncomePredictionService::class);

        $customerIds = IncomeData::revenueForYear($year)->distinct()->pluck('customer_id');

        return Customer::whereIn('id', $customerIds)->get()
            ->map(function (Customer $c) use ($year, $prediction) {
                $p = $prediction->predict($c, $year);
                $limit = (float) ($c->annual_revenue_limit ?? 85000);
                $actual = $p['current_total_sales'] ?? 0;
                $projected = $p['predicted_total_sales'] ?? $actual;

                return [
                    'customer' => $c,
                    'actual' => $actual,
                    'projected' => $projected,
                    'limit' => $limit,
                    'actual_pct' => $limit > 0 ? min(100, $actual / $limit * 100) : 0,
                    'projected_pct' => $limit > 0 ? $projected / $limit * 100 : 0,
                ];
            })
            ->filter(fn ($r) => $r['projected_pct'] >= 80)
            ->sortByDesc('projected_pct')
            ->take(8)
            ->values()
            ->all();
    }
}
