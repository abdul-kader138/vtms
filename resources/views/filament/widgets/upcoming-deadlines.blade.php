<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-calendar-days">
        <x-slot name="heading">{{ __('Upcoming deadlines') }}</x-slot>
        <x-slot name="headerEnd">
            <x-filament::link :href="\App\Filament\Pages\PaymentCalendar::getUrl()" size="sm">{{ __('Payment calendar') }}</x-filament::link>
        </x-slot>

        @php $entries = $this->getEntries(); @endphp

        @if ($entries->isEmpty())
            <p class="vt-muted" style="font-size:.875rem">{{ __('Nothing due in the next 45 days and nothing overdue.') }}</p>
        @else
            <div class="vt-list">
                @foreach ($entries as $e)
                    <div class="vt-list-item">
                        <div @class(['vt-date', 'vt-date--overdue' => $e['status'] === 'overdue'])>
                            <b>{{ $e['due_date']->format('d') }}</b>
                            <span>{{ $e['due_date']->translatedFormat('M') }}</span>
                        </div>
                        <div class="vt-grow">
                            <a href="{{ \App\Filament\Pages\CustomerTaxDashboard::getUrl(['customer' => $e['customer']->id]) }}" class="vt-truncate" style="display:block;font-weight:500;font-size:.875rem">
                                {{ $e['customer_name'] }}
                            </a>
                            <div class="vt-muted vt-small vt-truncate">
                                {{ $e['description'] }}
                                @if ($e['f24']) · <span class="vt-code">{{ $e['f24'] }}</span> @endif
                            </div>
                        </div>
                        <div class="vt-num" style="font-size:.875rem;font-weight:600">
                            € {{ number_format($e['outstanding'], 2, ',', '.') }}
                            @if ($e['status'] === 'overdue')
                                <div><x-filament::badge color="danger" size="sm">{{ __('Overdue') }}</x-filament::badge></div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
