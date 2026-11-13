<?php

namespace App\Filament\Widgets;

use App\Services\Tax\PaymentCalendarService;
use Filament\Widgets\ChartWidget;

class PaymentsDueChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '260px';

    public function getHeading(): ?string
    {
        return __('Unpaid amounts by due month — next 12 months');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $start = today()->startOfMonth();
        $entries = app(PaymentCalendarService::class)
            ->entries($start, $start->copy()->addMonths(12)->subDay(), status: 'open');

        $labels = [];
        $values = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $labels[] = $month->translatedFormat('M y');
            $values[] = round($entries->filter(fn ($e) => $e['due_date']->isSameMonth($month))->sum('outstanding'), 2);
        }

        return [
            'datasets' => [[
                'label' => __('Unpaid (€)'),
                'data' => $values,
                'borderRadius' => 4,
                'maxBarThickness' => 28,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'grid' => ['color' => 'rgba(148,163,184,0.15)'], 'border' => ['display' => false]],
            ],
        ];
    }
}
