@if (!empty($items))
<x-filament::section icon="heroicon-o-exclamation-circle" collapsible :collapsed="true">
    <x-slot name="heading">{{ __('Hidden costs & things to budget for') }}</x-slot>
    <x-slot name="description">{{ __('Ancillary costs Regime Forfettario does not show on the tax calculation itself.') }}</x-slot>

    <div class="vt-list">
        @foreach ($items as $item)
            <div class="vt-list-item">
                <div class="vt-grow">
                    <div>{{ $item['label'] }}</div>
                    <div class="vt-muted vt-small">{{ $item['note'] }}</div>
                </div>
                @if ($item['amount'] !== null)
                    <div class="vt-num">€ {{ number_format($item['amount'], 2, ',', '.') }}</div>
                @endif
            </div>
        @endforeach
    </div>
</x-filament::section>
@endif
