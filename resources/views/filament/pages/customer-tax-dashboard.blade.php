@php
    $tax = $this->getTaxCalculation();
    $taxPayments = $this->getTaxPayments();
    $inps = $this->getInpsCalculation();
    $inpsPayments = $this->getInpsPayments();
    $inail = $this->getInailCalculation();
    $inailPayment = $this->getInailPayment();
    $diritto = $this->getDirittoAnnualePayment();
    $fattura = $this->getFatturaCalculation();
    $fatturaPayment = $this->getFatturaPayment();
@endphp

<x-filament-panels::page>
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
            <x-filament::button tag="a" href="{{ $this->previousYearUrl() }}" color="gray" icon="heroicon-o-chevron-left" size="sm">
                {{ $this->selectedYear - 1 }}
            </x-filament::button>
            <span class="text-lg font-semibold">{{ $this->selectedYear }}</span>
            <x-filament::button tag="a" href="{{ $this->nextYearUrl() }}" color="gray" icon-position="after" icon="heroicon-o-chevron-right" size="sm">
                {{ $this->selectedYear + 1 }}
            </x-filament::button>
        </div>
        <x-filament::button tag="a" href="{{ $this->historicalYearUrl() }}" color="gray" icon="heroicon-o-archive-box">
            {{ __('Enter historical year') }}
        </x-filament::button>
    </div>

    {{-- Tax --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('Regime Forfettario tax') }}</x-slot>

        @if ($tax)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-4">
                @include('filament.pages.partials.stat', ['label' => __('Total sales'), 'value' => $tax->total_sales])
                @include('filament.pages.partials.stat', ['label' => __('Taxable income'), 'value' => $tax->taxable_income])
                @include('filament.pages.partials.stat', ['label' => __('Tax due'), 'value' => $tax->tax_due])
                @include('filament.pages.partials.stat', ['label' => __('Balance payment'), 'value' => $tax->balance_payment])
            </div>
            @include('filament.pages.partials.payment-table', ['payments' => $taxPayments, 'type' => 'tax', 'labelKey' => 'payment_type', 'customer' => $this->customerRecord])
        @else
            <p class="text-sm text-gray-500">{{ __('Not yet calculated for this year.') }}</p>
        @endif
    </x-filament::section>

    {{-- INPS --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('INPS') }}</x-slot>

        @if ($inps)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-4">
                @include('filament.pages.partials.stat', ['label' => __('INPS amount'), 'value' => $inps->inps_amount])
                @include('filament.pages.partials.stat', ['label' => __('Discount').' ('.$inps->discount_percentage.'%)', 'value' => $inps->discount_amount])
                @include('filament.pages.partials.stat', ['label' => __('After discount'), 'value' => $inps->inps_amount_after_discount])
                @include('filament.pages.partials.stat', ['label' => __('Method'), 'value' => $inps->calculation_method?->value, 'isCurrency' => false])
            </div>
            @include('filament.pages.partials.payment-table', ['payments' => $inpsPayments, 'type' => 'inps', 'labelKey' => 'installment_number', 'customer' => $this->customerRecord])
        @else
            <p class="text-sm text-gray-500">{{ __('Not yet calculated for this year.') }}</p>
        @endif
    </x-filament::section>

    {{-- INAIL --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('INAIL') }}</x-slot>

        @if ($inail)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-4">
                @include('filament.pages.partials.stat', ['label' => __('Calculated amount'), 'value' => $inail->inail_calculated_amount])
                @include('filament.pages.partials.stat', ['label' => __('Minimum payment'), 'value' => $inail->inail_minimum_payment])
                @include('filament.pages.partials.stat', ['label' => __('Final amount'), 'value' => $inail->inail_final_amount])
            </div>
            @if ($inailPayment)
                @include('filament.pages.partials.payment-table', ['payments' => collect([$inailPayment]), 'type' => 'inail', 'labelKey' => null, 'customer' => $this->customerRecord])
            @endif
        @else
            <p class="text-sm text-gray-500">{{ __('Not applicable — INAIL only applies to Artigiani customers with rate and minimum payment configured.') }}</p>
        @endif
    </x-filament::section>

    {{-- Diritto Annuale --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('Diritto Annuale') }}</x-slot>

        @if ($diritto)
            @include('filament.pages.partials.payment-table', ['payments' => collect([$diritto]), 'type' => 'diritto_annuale', 'labelKey' => null, 'customer' => $this->customerRecord])
        @else
            <p class="text-sm text-gray-500">{{ __('No matching bracket / not applicable for this customer type.') }}</p>
        @endif
    </x-filament::section>

    {{-- Fattura Tra Privati --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('Fattura Tra Privati (stamp duty)') }}</x-slot>

        @if ($fattura)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-4">
                @include('filament.pages.partials.stat', ['label' => __('Qualifying invoices'), 'value' => $fattura->total_invoices, 'isCurrency' => false])
                @include('filament.pages.partials.stat', ['label' => __('Total payment'), 'value' => $fattura->total_payment_amount])
            </div>
            @if ($fatturaPayment)
                @include('filament.pages.partials.payment-table', ['payments' => collect([$fatturaPayment]), 'type' => 'fattura_tra_privati', 'labelKey' => null, 'customer' => $this->customerRecord])
            @endif
        @else
            <p class="text-sm text-gray-500">{{ __('No qualifying invoices (≥ stamp duty threshold) for this year.') }}</p>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
