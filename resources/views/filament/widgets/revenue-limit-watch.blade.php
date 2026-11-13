<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-shield-exclamation">
        <x-slot name="heading">{{ __('Revenue limit watch :year', ['year' => now()->year]) }}</x-slot>
        <x-slot name="description">{{ __('Customers at 80% or more of their Regime Forfettario limit, actual or projected.') }}</x-slot>

        @php $rows = $this->getRows(); @endphp

        @if ($rows === [])
            <p class="vt-muted" style="font-size:.875rem">{{ __('No customer is close to the limit.') }}</p>
        @else
            <div class="vt-list">
                @foreach ($rows as $r)
                    <div class="vt-list-item" style="display:block">
                        <div style="display:flex;justify-content:space-between;gap:.75rem;font-size:.875rem;margin-bottom:.375rem">
                            <a href="{{ \App\Filament\Pages\CustomerTaxDashboard::getUrl(['customer' => $r['customer']->id]) }}" class="vt-truncate" style="font-weight:500">
                                {{ $r['customer']->company ?: $r['customer']->name }}
                            </a>
                            <span class="vt-num vt-muted">
                                € {{ number_format($r['actual'], 0, ',', '.') }}
                                @if ($r['projected'] > $r['actual'])
                                    → € {{ number_format($r['projected'], 0, ',', '.') }}
                                @endif
                                / € {{ number_format($r['limit'], 0, ',', '.') }}
                            </span>
                        </div>
                        <div class="vt-bar" role="img" aria-label="{{ __(':a% of limit reached, :p% projected', ['a' => round($r['actual_pct']), 'p' => round($r['projected_pct'])]) }}">
                            <i class="vt-bar-projected" style="width: {{ min(100, $r['projected_pct']) }}%"></i>
                            <i @class(['vt-bar-actual', 'over' => $r['actual_pct'] >= 100]) style="width: {{ $r['actual_pct'] }}%"></i>
                        </div>
                        <div class="vt-muted vt-small" style="margin-top:.25rem">
                            {{ __(':a% reached', ['a' => round($r['actual_pct'])]) }}
                            @if ($r['projected'] > $r['actual'])
                                · {{ __(':p% projected for the full year', ['p' => round($r['projected_pct'])]) }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
