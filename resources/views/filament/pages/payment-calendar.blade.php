@php
    $entries = $this->getEntries();
    $totals = $this->getTotals($entries);
    $byMonth = $entries->groupBy(fn ($e) => $e['due_date']->format('Y-m'));
    $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div class="vt-filters">
            <label>
                {{ __('Period') }}
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="period">
                        @foreach ($this->periodOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label>
                {{ __('Status') }}
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="status">
                        @foreach ($this->statusOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label>
                {{ __('Payment type') }}
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="type">
                        <option value="">{{ __('All types') }}</option>
                        @foreach (\App\Services\Tax\PaymentRegistry::LABELS as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label style="flex:1;min-width:12rem">
                {{ __('Customer') }}
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Name or company') }}" />
                </x-filament::input.wrapper>
            </label>
            <x-filament::button color="gray" size="sm" wire:click="resetFilters" icon="heroicon-m-x-mark">{{ __('Reset') }}</x-filament::button>
        </div>
    </x-filament::section>

    <div class="vt-stats">
        <div class="vt-stat"><div class="vt-stat-label">{{ __('Payments shown') }}</div><div class="vt-stat-value">{{ $totals['count'] }}</div></div>
        <div class="vt-stat vt-stat--primary"><div class="vt-stat-label">{{ __('Unpaid') }}</div><div class="vt-stat-value">{{ $eur($totals['outstanding']) }}</div></div>
        <div @class(['vt-stat', 'vt-stat--danger' => $totals['overdue_count'] > 0])><div class="vt-stat-label">{{ __('Overdue (:n)', ['n' => $totals['overdue_count']]) }}</div><div class="vt-stat-value">{{ $eur($totals['overdue']) }}</div></div>
        <div class="vt-stat vt-stat--success"><div class="vt-stat-label">{{ __('Paid') }}</div><div class="vt-stat-value">{{ $eur($totals['paid']) }}</div></div>
    </div>

    <x-filament::section>
        @if ($entries->isEmpty())
            <div style="text-align:center;padding:2rem 0">
                <x-filament::icon icon="heroicon-o-calendar" style="width:2.5rem;height:2.5rem;margin:0 auto;color:rgb(var(--gray-400))" />
                <p style="font-weight:500;margin-top:.5rem">{{ __('No payments match these filters') }}</p>
                <p class="vt-muted" style="font-size:.875rem">{{ __('Try a longer period or "Any status".') }}</p>
            </div>
        @else
            <div class="vt-table-wrap">
                <table class="vt-table">
                    <thead>
                        <tr>
                            <th>{{ __('Due') }}</th>
                            <th>{{ __('Customer') }}</th>
                            <th>{{ __('Payment') }}</th>
                            <th>{{ __('F24') }}</th>
                            <th class="vt-num">{{ __('Amount') }}</th>
                            <th class="vt-num">{{ __('Outstanding') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    @foreach ($byMonth as $month => $rows)
                        <tbody>
                            <tr>
                                <td colspan="8" class="vt-group-heading" style="border-top:0">
                                    {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y') }}
                                    <span class="vt-muted" style="font-weight:400"> · {{ $eur($rows->whereIn('status', ['pending', 'overdue'])->sum('outstanding')) }} {{ __('unpaid') }}</span>
                                </td>
                            </tr>
                            @foreach ($rows as $e)
                                <tr @class(['vt-row--overdue' => $e['status'] === 'overdue'])>
                                    <td class="vt-num" style="text-align:left">{{ $e['due_date']->format('d/m/Y') }}</td>
                                    <td>
                                        <a href="{{ \App\Filament\Pages\CustomerTaxDashboard::getUrl(['customer' => $e['customer']->id, 'year' => $e['payment']->payment_year ?? $e['payment']->tax_year]) }}" style="font-weight:500">
                                            {{ $e['customer_name'] }}
                                        </a>
                                    </td>
                                    <td>{{ $e['description'] }}</td>
                                    <td>@if ($e['f24'])<span class="vt-code">{{ $e['f24'] }}</span>@else<span class="vt-muted">—</span>@endif</td>
                                    <td class="vt-num">{{ $eur($e['amount']) }}</td>
                                    <td class="vt-num">{{ $e['outstanding'] > 0 ? $eur($e['outstanding']) : '—' }}</td>
                                    <td>
                                        <x-filament::badge :color="match ($e['status']) { 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'gray', default => 'warning' }">
                                            {{ match ($e['status']) { 'paid' => __('Paid'), 'overdue' => __('Overdue'), 'cancelled' => __('Cancelled'), default => __('Upcoming') } }}
                                        </x-filament::badge>
                                    </td>
                                    <td>
                                        <div class="vt-actions">
                                            @if (in_array($e['status'], ['pending', 'overdue'], true))
                                                <button type="button" class="vt-iconbtn" title="{{ __('Record payment') }}" aria-label="{{ __('Record payment') }}"
                                                        wire:click="mountAction('markPaid', { type: '{{ $e['type'] }}', id: {{ $e['id'] }} })">
                                                    <x-filament::icon icon="heroicon-o-check-circle" />
                                                </button>
                                                <a href="{{ \App\Filament\Pages\CustomerTaxDashboard::f24SheetUrl($e['customer']->id, $e['due_date'], today()) }}" class="vt-iconbtn"
                                                   title="{{ $e['status'] === 'overdue' ? __('F24 sheet with late-payment cost (paying today)') : __('F24 sheet for this deadline') }}" aria-label="{{ __('F24 sheet') }}">
                                                    <x-filament::icon icon="heroicon-o-document-currency-euro" />
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
