@php
    $overview = $this->getOverview();
    $tax = $overview['tax'];
    $inps = $overview['inps'];
    $inail = $overview['inail'];
    $fattura = $overview['fattura_tra_privati'];
    $payments = $overview['payments'];
    $summary = $overview['summary'];
    $estimate = $overview['estimate'];
    $prediction = $overview['prediction'];
    $monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
@endphp

<x-filament-panels::page>
    @php
        $c = $this->customerRecord;
        $group = \App\Support\Tax\AtecoGroup::forAtecoCode($c->ateco_code);
        $rateThisYear = $c->taxRateFor($this->selectedYear);
        $startupEnds = $c->startupRateEndsAfter();
        $isLaw = \App\Support\Tax\TaxRules::isLaw();
        $portalUsers = $c->users()->count();
    @endphp

    {{-- Year navigation --}}
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem">
        <div style="display:flex;align-items:center;gap:.5rem">
            <x-filament::button tag="a" href="{{ $this->previousYearUrl() }}" color="gray" icon="heroicon-m-chevron-left" size="sm" :tooltip="__('Previous year')">
                {{ $this->selectedYear - 1 }}
            </x-filament::button>
            <span style="font-size:1.25rem;font-weight:700;padding:0 .5rem">{{ $this->selectedYear }}</span>
            <x-filament::button tag="a" href="{{ $this->nextYearUrl() }}" color="gray" icon-position="after" icon="heroicon-m-chevron-right" size="sm" :tooltip="__('Next year')">
                {{ $this->selectedYear + 1 }}
            </x-filament::button>
            @if ($tax)
                <x-filament::badge :color="$tax->rules === 'law' ? 'success' : 'gray'" icon="heroicon-m-scale" style="margin-left:.5rem">
                    {{ $tax->rules === 'law' ? __('Calculated with Italian law rules') : __('Calculated with VTMS legacy rules') }}
                </x-filament::badge>
            @endif
        </div>
        <x-filament::button tag="a" href="{{ $this->historicalYearUrl() }}" color="gray" icon="heroicon-o-archive-box" size="sm">
            {{ __('Enter historical year') }}
        </x-filament::button>
    </div>

    {{-- Customer profile --}}
    <x-filament::section icon="heroicon-o-building-office" collapsible :collapsed="false" compact>
        <x-slot name="heading">{{ $c->company ?: $c->name }}</x-slot>
        <x-slot name="description">{{ collect([$c->company ? $c->name : null, $c->vat_no ? __('P.IVA :v', ['v' => $c->vat_no]) : null, $c->city])->filter()->implode(' · ') }}</x-slot>
        <x-slot name="headerEnd">
            <x-filament::link :href="\App\Filament\Resources\CustomerResource::getUrl('edit', ['record' => $c->id])" icon="heroicon-m-pencil-square" size="sm">{{ __('Edit settings') }}</x-filament::link>
        </x-slot>

        <dl class="vt-profile">
            <div><dt>{{ __('INPS category') }}</dt><dd>{{ $c->customer_type?->label() ?? '—' }}</dd></div>
            <div>
                <dt>{{ __('Activity (ATECO)') }}</dt>
                <dd>{{ $c->ateco_code ?: '—' }}@if ($group)<div class="vt-muted vt-small">{{ $group->label() }}</div>@endif</dd>
            </div>
            <div>
                <dt>{{ __('Coefficient') }}</dt>
                <dd>
                    {{ rtrim(rtrim(number_format((float) $c->coefficient_of_profitability, 2), '0'), '.') }}%
                    @if ($group && (float) $c->coefficient_of_profitability !== $group->coefficient())
                        <div class="vt-small" style="color:rgb(var(--warning-600))">{{ __('Legal for this ATECO: :c%', ['c' => $group->coefficient()]) }}</div>
                    @endif
                </dd>
            </div>
            <div>
                <dt>{{ __('Tax rate :y', ['y' => $this->selectedYear]) }}</dt>
                <dd>{{ rtrim(rtrim(number_format($rateThisYear, 2), '0'), '.') }}%@if ($startupEnds)<div class="vt-muted vt-small">{{ $this->selectedYear <= $startupEnds ? __('start-up rate until :y', ['y' => $startupEnds]) : __('start-up rate ended :y', ['y' => $startupEnds]) }}</div>@endif</dd>
            </div>
            <div><dt>{{ __('In business since') }}</dt><dd>{{ $c->business_start_date?->format('d/m/Y') ?? '—' }}</dd></div>
            <div>
                <dt>{{ __('Customer portal') }}</dt>
                <dd>{{ $portalUsers > 0 ? trans_choice(':count login|:count logins', $portalUsers) : __('No login linked') }}</dd>
            </div>
        </dl>
    </x-filament::section>

    {{-- Regime Forfettario eligibility --}}
    @foreach ($overview['warnings'] as $warning)
        <div class="vt-alert vt-alert--{{ $warning['level'] }}" role="status">
            <x-filament::icon :icon="match ($warning['level']) { 'danger' => 'heroicon-m-x-circle', 'warning' => 'heroicon-m-exclamation-triangle', default => 'heroicon-m-information-circle' }" />
            <span>{{ $warning['message'] }}</span>
        </div>
    @endforeach

    {{-- Payment totals across all five payment types --}}
    <div class="vt-stats">
        @include('filament.pages.partials.stat', ['label' => __('Outstanding'), 'value' => $summary['total_due'], 'tone' => 'primary'])
        @include('filament.pages.partials.stat', ['label' => __('Overdue (:n)', ['n' => $summary['overdue_count']]), 'value' => $summary['total_overdue'], 'tone' => $summary['overdue_count'] > 0 ? 'danger' : null])
        @include('filament.pages.partials.stat', ['label' => __('Upcoming (:n)', ['n' => $summary['upcoming_count']]), 'value' => $summary['total_upcoming']])
        @include('filament.pages.partials.stat', ['label' => __('Paid'), 'value' => $summary['total_paid'], 'tone' => 'success'])
    </div>

    {{-- Live estimate (before a calculation exists) and full-year prediction --}}
    @if ($estimate || ($prediction && $prediction['missing_months'] !== []))
        <x-filament::section icon="heroicon-o-arrow-trending-up">
            <x-slot name="heading">{{ __('Estimate & prediction') }}</x-slot>
            <x-slot name="description">{{ __('Recomputed live from imported income data — not saved until you recalculate.') }}</x-slot>

            @if ($estimate)
                <div class="vt-stats vt-mb">
                    @include('filament.pages.partials.stat', ['label' => __('Sales so far'), 'value' => $estimate['total_sales']])
                    @include('filament.pages.partials.stat', ['label' => __('Taxable income (:c%)', ['c' => $estimate['coefficient_used']]), 'value' => $estimate['taxable_income']])
                    @include('filament.pages.partials.stat', ['label' => __('Estimated tax (:r%)', ['r' => $estimate['tax_rate']]), 'value' => $estimate['estimated_tax'], 'tone' => 'primary'])
                </div>
            @endif

            @if ($prediction && $prediction['missing_months'] !== [])
                <div class="vt-stats vt-mb">
                    @include('filament.pages.partials.stat', ['label' => __('Average monthly sales'), 'value' => $prediction['average_monthly_sales']])
                    @include('filament.pages.partials.stat', ['label' => __('Predicted total sales'), 'value' => $prediction['predicted_total_sales']])
                    @include('filament.pages.partials.stat', ['label' => __('Predicted taxable income'), 'value' => $prediction['predicted_taxable_income']])
                    @include('filament.pages.partials.stat', ['label' => __('Predicted tax due'), 'value' => $prediction['predicted_tax_due'], 'tone' => 'primary'])
                </div>
                <div class="vt-months">
                    <span class="vt-muted" style="margin-right:.5rem">{{ __(':n/12 months with data', ['n' => count($prediction['months_with_data'])]) }}</span>
                    @foreach ($monthNames as $num => $name)
                        <span @class(['vt-month', 'vt-month--on' => in_array($num, $prediction['months_with_data'], true)])>{{ __($name) }}</span>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Tax --}}
    <x-filament::section icon="heroicon-o-receipt-percent">
        <x-slot name="heading">{{ __('Flat tax (imposta sostitutiva)') }}</x-slot>

        @if ($tax)
            <div class="vt-stats vt-mb">
                @include('filament.pages.partials.stat', ['label' => __('Total sales'), 'value' => $tax->total_sales])
                @if ((float) $tax->inps_deducted > 0)
                    @include('filament.pages.partials.stat', ['label' => __('Gross income (:c%)', ['c' => (float) $tax->coefficient_used]), 'value' => $tax->grossIncome()])
                    @include('filament.pages.partials.stat', ['label' => __('INPS paid in :y (deducted)', ['y' => $this->selectedYear]), 'value' => -1 * (float) $tax->inps_deducted])
                    @include('filament.pages.partials.stat', ['label' => __('Taxable income'), 'value' => $tax->taxable_income])
                @else
                    @include('filament.pages.partials.stat', ['label' => __('Taxable income (:c%)', ['c' => (float) $tax->coefficient_used]), 'value' => $tax->taxable_income])
                @endif
                @include('filament.pages.partials.stat', ['label' => __('Tax due (:r%)', ['r' => (float) $tax->tax_rate_used]), 'value' => $tax->tax_due, 'tone' => 'primary'])
                @include('filament.pages.partials.stat', ['label' => __('Advances already paid toward :y', ['y' => $this->selectedYear]), 'value' => $tax->advance_payments_made])
                @include('filament.pages.partials.stat', ['label' => __('Balance to pay'), 'value' => $tax->balance_payment])
                @include('filament.pages.partials.stat', ['label' => __('Advance base for :y (:p%)', ['y' => $this->selectedYear + 1, 'p' => $tax->rules === 'law' ? 100 : 80]), 'value' => $tax->next_year_advance_base])
                @include('filament.pages.partials.stat', ['label' => __('Previous year INPS'), 'value' => $overview['previous_year_inps']])
            </div>
            @if ($payments['tax']->isNotEmpty())
                @include('filament.pages.partials.payment-table', ['payments' => $payments['tax'], 'type' => 'tax', 'customer' => $c])
            @else
                <p class="vt-muted" style="font-size:.875rem">{{ __('No tax payment is due for this year.') }}</p>
            @endif
        @else
            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                <p class="vt-muted" style="font-size:.875rem">{{ __('Not calculated for :y yet.', ['y' => $this->selectedYear]) }}</p>
                <x-filament::button size="sm" icon="heroicon-m-calculator" wire:click="mountAction('recalculate')">{{ __('Calculate :y', ['y' => $this->selectedYear]) }}</x-filament::button>
            </div>
        @endif
    </x-filament::section>

    {{-- INPS --}}
    <x-filament::section icon="heroicon-o-user-group">
        <x-slot name="heading">{{ __('INPS') }}</x-slot>

        @if ($inps)
            @if ($inps->notes)
                <div class="vt-alert vt-alert--warning vt-mb" role="status">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                    <span>{{ __('INPS rules used: :source', ['source' => $inps->notes]) }}</span>
                </div>
            @endif

            <div class="vt-stats vt-mb">
                @if ($inps->fixed_amount !== null)
                    @include('filament.pages.partials.stat', ['label' => __('Fixed contributions'), 'value' => $inps->fixed_amount, 'hint' => __('on the minimum income, due in 4 installments')])
                    @include('filament.pages.partials.stat', ['label' => __('On income above the minimum'), 'value' => $inps->variable_amount, 'hint' => __('paid with the income tax')])
                @endif
                @include('filament.pages.partials.stat', ['label' => __('INPS amount'), 'value' => $inps->inps_amount])
                @include('filament.pages.partials.stat', ['label' => __('Discount').' ('.$inps->discount_percentage.'%)', 'value' => $inps->discount_amount])
                @include('filament.pages.partials.stat', ['label' => __('After discount'), 'value' => $inps->inps_amount_after_discount])
                @include('filament.pages.partials.stat', ['label' => __('Average rate'), 'value' => $inps->inps_rate.'% · '.match ($inps->calculation_method?->value) { 'fixed_plus_percentage' => __('fixed + %'), 'minimum' => __('minimum'), 'percentage' => __('percentage'), default => '—' }, 'isCurrency' => false])
            </div>

            @if (! empty($inps->slab_details))
                <details class="vt-mb" style="font-size:.875rem">
                    <summary class="vt-muted" style="cursor:pointer">{{ __('Slab breakdown') }}</summary>
                    <table class="vt-table">
                        <thead>
                            <tr>
                                <th class="py-1 pr-4">{{ __('From') }}</th>
                                <th class="py-1 pr-4">{{ __('To') }}</th>
                                <th class="py-1 pr-4">{{ __('Income in slab') }}</th>
                                <th class="py-1 pr-4">{{ __('Rate') }}</th>
                                <th class="py-1">{{ __('INPS') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($inps->slab_details as $slab)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1 pr-4">€ {{ number_format((float) $slab['income_from'], 2) }}</td>
                                    <td class="py-1 pr-4">{{ $slab['income_to'] === null ? '∞' : '€ '.number_format((float) $slab['income_to'], 2) }}</td>
                                    <td class="py-1 pr-4">{{ ($slab['method'] ?? null) === 'minimum' ? __('fixed minimum').(isset($slab['months']) && $slab['months'] < 12 ? ' · '.__(':n months', ['n' => $slab['months']]) : '') : '€ '.number_format((float) $slab['portion'], 2) }}</td>
                                    <td class="py-1 pr-4">{{ ($slab['method'] ?? null) === 'minimum' ? '—' : $slab['rate'].'%' }}</td>
                                    <td class="py-1">€ {{ number_format((float) $slab['tax'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif

            @include('filament.pages.partials.payment-table', ['payments' => $payments['inps'], 'type' => 'inps', 'customer' => $this->customerRecord])
        @else
            <p class="vt-muted" style="font-size:.875rem">{{ __('Not yet calculated for this year.') }}</p>
        @endif
    </x-filament::section>

    {{-- INAIL --}}
    <x-filament::section icon="heroicon-o-lifebuoy" collapsible :collapsed="$inail === null">
        <x-slot name="heading">{{ __('INAIL') }}</x-slot>

        @if ($inail)
            <div class="vt-stats vt-mb">
                @include('filament.pages.partials.stat', ['label' => __('Calculated amount (:r%)', ['r' => $inail->inail_rate]), 'value' => $inail->inail_calculated_amount])
                @include('filament.pages.partials.stat', ['label' => __('Minimum payment'), 'value' => $inail->inail_minimum_payment])
                @include('filament.pages.partials.stat', ['label' => __('Final amount'), 'value' => $inail->inail_final_amount])
                @include('filament.pages.partials.stat', ['label' => __('ATECO code'), 'value' => $inail->ateco_code, 'isCurrency' => false])
            </div>
            @include('filament.pages.partials.payment-table', ['payments' => $payments['inail'], 'type' => 'inail', 'customer' => $this->customerRecord])
        @else
            <p class="vt-muted" style="font-size:.875rem">{{ __('Not applicable — INAIL only applies to Artigiani customers with rate and minimum payment configured.') }}</p>
        @endif
    </x-filament::section>

    {{-- Diritto Annuale --}}
    <x-filament::section icon="heroicon-o-building-library" collapsible :collapsed="$payments['diritto_annuale']->isEmpty()">
        <x-slot name="heading">{{ __('Diritto Annuale') }}</x-slot>

        @if ($payments['diritto_annuale']->isNotEmpty())
            @include('filament.pages.partials.payment-table', ['payments' => $payments['diritto_annuale'], 'type' => 'diritto_annuale', 'customer' => $this->customerRecord])
        @else
            <p class="vt-muted" style="font-size:.875rem">{{ __('No matching bracket / not applicable for this customer type.') }}</p>
        @endif
    </x-filament::section>

    {{-- Fattura Tra Privati --}}
    <x-filament::section icon="heroicon-o-document-text" collapsible :collapsed="$fattura === null">
        <x-slot name="heading">{{ __('Fattura Tra Privati (stamp duty)') }}</x-slot>

        @if ($fattura)
            <div class="vt-stats vt-mb">
                @include('filament.pages.partials.stat', ['label' => __('Qualifying invoices'), 'value' => $fattura->total_invoices, 'isCurrency' => false])
                @include('filament.pages.partials.stat', ['label' => __('Qualifying sales'), 'value' => $fattura->total_sales_amount])
                @include('filament.pages.partials.stat', ['label' => __('Fee per invoice'), 'value' => $fattura->payment_per_invoice])
                @include('filament.pages.partials.stat', ['label' => __('Total payment'), 'value' => $fattura->total_payment_amount])
            </div>
            @include('filament.pages.partials.payment-table', ['payments' => $payments['fattura_tra_privati'], 'type' => 'fattura_tra_privati', 'customer' => $this->customerRecord])
        @else
            <p class="vt-muted" style="font-size:.875rem">{{ __('No qualifying invoices (≥ stamp duty threshold) for this year.') }}</p>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
