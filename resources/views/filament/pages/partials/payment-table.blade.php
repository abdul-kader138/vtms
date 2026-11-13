@props(['payments', 'type', 'labelKey', 'customer'])

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                @if ($labelKey)
                    <th class="py-1 pr-4">#</th>
                @endif
                <th class="py-1 pr-4">{{ __('Due date') }}</th>
                <th class="py-1 pr-4">{{ __('Amount') }}</th>
                <th class="py-1 pr-4">{{ __('Status') }}</th>
                <th class="py-1 pr-4">{{ __('Paid amount') }}</th>
                <th class="py-1 pr-4">{{ __('Paid date') }}</th>
                <th class="py-1 pr-4">{{ __('Receipt') }}</th>
                <th class="py-1"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payments as $payment)
                <tr class="border-t border-gray-100 dark:border-white/5">
                    @if ($labelKey)
                        <td class="py-1.5 pr-4">{{ data_get($payment, $labelKey) instanceof \BackedEnum ? data_get($payment, $labelKey)->value : data_get($payment, $labelKey) }}</td>
                    @endif
                    <td class="py-1.5 pr-4">{{ $payment->due_date?->format('d/m/Y') }}</td>
                    <td class="py-1.5 pr-4">€ {{ number_format((float) $payment->amount, 2) }}</td>
                    <td class="py-1.5 pr-4">
                        <x-filament::badge :color="match ($payment->status?->value) {
                            'paid' => 'success',
                            'overdue' => 'danger',
                            'cancelled' => 'gray',
                            default => 'warning',
                        }">
                            {{ ucfirst($payment->status?->value ?? 'pending') }}
                        </x-filament::badge>
                    </td>
                    <td class="py-1.5 pr-4">
                        € {{ number_format((float) $payment->paid_amount, 2) }}
                        @php $remaining = round((float) $payment->amount - (float) $payment->paid_amount, 2); @endphp
                        @if ($remaining > 0 && (float) $payment->paid_amount > 0)
                            <span class="text-xs text-gray-500">({{ __('€:amount remaining', ['amount' => number_format($remaining, 2)]) }})</span>
                        @endif
                    </td>
                    <td class="py-1.5 pr-4">{{ $payment->paid_date?->format('d/m/Y') ?? '—' }}</td>
                    <td class="py-1.5 pr-4">
                        @if ($payment->uploaded_pdf)
                            <a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('tax.payments.receipt.download', now()->addMinutes(30), ['customer' => $customer->id, 'type' => $type, 'id' => $payment->id]) }}" target="_blank" class="text-primary-600 underline">{{ __('View') }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="py-1.5 text-right space-x-2 whitespace-nowrap">
                        <button type="button" wire:click="mountAction('markPaid', { type: '{{ $type }}', id: {{ $payment->id }} })" class="text-xs text-primary-600 underline">
                            {{ __('Mark paid') }}
                        </button>
                        <button type="button" wire:click="mountAction('uploadReceipt', { type: '{{ $type }}', id: {{ $payment->id }} })" class="text-xs text-primary-600 underline">
                            {{ __('Upload receipt') }}
                        </button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
