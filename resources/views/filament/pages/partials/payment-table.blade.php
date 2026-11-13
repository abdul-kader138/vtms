@props(['payments', 'type', 'labelKey' => null, 'customer'])

<div class="vt-table-wrap">
    <table class="vt-table">
        <thead>
            <tr>
                <th>{{ __('Payment') }}</th>
                <th>{{ __('F24') }}</th>
                <th>{{ __('Due') }}</th>
                <th class="vt-num">{{ __('Amount') }}</th>
                <th>{{ __('Status') }}</th>
                <th class="vt-num">{{ __('Paid') }}</th>
                <th>{{ __('Paid on') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payments as $payment)
                @php
                    $status = \App\Services\Tax\TaxOverviewService::effectiveStatus($payment);
                    $f24 = \App\Support\Tax\F24Code::for($type, $payment);
                    $remaining = round((float) $payment->amount - (float) $payment->paid_amount, 2);
                    $receiptUrl = $payment->uploaded_pdf
                        ? \Illuminate\Support\Facades\URL::temporarySignedRoute('tax.payments.receipt.download', now()->addMinutes(30), ['customer' => $customer->id, 'type' => $type, 'id' => $payment->id])
                        : null;
                @endphp
                <tr @class(['vt-row--overdue' => $status === 'overdue'])>
                    <td>
                        {{ \Illuminate\Support\Str::after(\App\Services\Tax\PaymentRegistry::describe($type, $payment), ' — ') ?: \App\Services\Tax\PaymentRegistry::describe($type, $payment) }}
                        @if ($type === 'tax')
                            <span class="vt-muted vt-small">({{ __('for :y', ['y' => $payment->tax_year]) }})</span>
                        @endif
                    </td>
                    <td>@if ($f24)<span class="vt-code">{{ $f24 }}</span>@else<span class="vt-muted">—</span>@endif</td>
                    <td style="white-space:nowrap">{{ $payment->due_date?->format('d/m/Y') }}</td>
                    <td class="vt-num">€ {{ number_format((float) $payment->amount, 2, ',', '.') }}</td>
                    <td>
                        <x-filament::badge :color="match ($status) { 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'gray', default => 'warning' }">
                            {{ match ($status) { 'paid' => __('Paid'), 'overdue' => __('Overdue'), 'cancelled' => __('Cancelled'), default => __('Upcoming') } }}
                        </x-filament::badge>
                    </td>
                    <td class="vt-num">
                        € {{ number_format((float) $payment->paid_amount, 2, ',', '.') }}
                        @if ($remaining > 0 && (float) $payment->paid_amount > 0)
                            <div class="vt-muted vt-small">{{ __('€:amount left', ['amount' => number_format($remaining, 2, ',', '.')]) }}</div>
                        @endif
                    </td>
                    <td style="white-space:nowrap">{{ $payment->paid_date?->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        <div class="vt-actions">
                            @if ($status !== 'paid')
                                <button type="button" class="vt-iconbtn" title="{{ __('Mark paid') }}" aria-label="{{ __('Mark paid') }}" wire:click="mountAction('markPaid', { type: '{{ $type }}', id: {{ $payment->id }} })">
                                    <x-filament::icon icon="heroicon-o-check-circle" />
                                </button>
                            @endif
                            @if ($status === 'overdue' && \App\Services\Tax\RavvedimentoService::supports($type))
                                <button type="button" class="vt-iconbtn" style="color:rgb(var(--danger-600))" title="{{ __('Late-payment cost') }}" aria-label="{{ __('Late-payment cost') }}" wire:click="mountAction('lateCost', { type: '{{ $type }}', id: {{ $payment->id }} })">
                                    <x-filament::icon icon="heroicon-o-calculator" />
                                </button>
                            @endif
                            <button type="button" class="vt-iconbtn" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}" wire:click="mountAction('editPayment', { type: '{{ $type }}', id: {{ $payment->id }} })">
                                <x-filament::icon icon="heroicon-o-pencil-square" />
                            </button>
                            <button type="button" class="vt-iconbtn" title="{{ __('Upload receipt') }}" aria-label="{{ __('Upload receipt') }}" wire:click="mountAction('uploadReceipt', { type: '{{ $type }}', id: {{ $payment->id }} })">
                                <x-filament::icon icon="heroicon-o-paper-clip" />
                            </button>
                            @if ($receiptUrl)
                                <a href="{{ $receiptUrl }}" target="_blank" class="vt-iconbtn" title="{{ __('View receipt') }}" aria-label="{{ __('View receipt') }}">
                                    <x-filament::icon icon="heroicon-o-document-check" />
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
