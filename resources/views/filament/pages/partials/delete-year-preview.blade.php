<div style="font-size:.875rem">
    @if (! $preview['has_data'])
        <p class="vt-muted">{{ __('Nothing to delete — :year has no tax figures for this customer.', ['year' => $year]) }}</p>
    @else
        <table class="vt-table">
            <tbody>
                <tr><td>{{ __('Tax calculation') }}</td><td class="vt-num">{{ $preview['counts']['tax_calculation'] }}</td></tr>
                <tr><td>{{ __('INPS calculation') }}</td><td class="vt-num">{{ $preview['counts']['inps_calculation'] }}</td></tr>
                <tr><td>{{ __('INAIL calculation') }}</td><td class="vt-num">{{ $preview['counts']['inail_calculation'] }}</td></tr>
                <tr><td>{{ __('Fattura Tra Privati calculation') }}</td><td class="vt-num">{{ $preview['counts']['fattura_tra_privati_calculation'] }}</td></tr>
                <tr><td>{{ __('Diritto Annuale payment') }}</td><td class="vt-num">{{ $preview['counts']['diritto_annuale_payment'] }}</td></tr>
                <tr><td style="font-weight:600">{{ __('Payments that would be removed') }}</td><td class="vt-num" style="font-weight:600">{{ $preview['counts']['payments'] }}</td></tr>
            </tbody>
        </table>

        @if ($preview['locked'])
            <p style="margin-top:.75rem; font-weight:600; color:var(--vt-danger, #dc2626)">
                {{ __('Blocked — :year is locked. Unlock it first if this year genuinely needs to be removed.', ['year' => $year]) }}
            </p>
        @elseif ($preview['deletable'])
            <p class="vt-muted vt-small" style="margin-top:.5rem">{{ __('None of these payments have been paid, cancelled, receipted or self-reported — safe to delete.') }}</p>
        @else
            <p style="margin-top:.75rem; font-weight:600; color:var(--vt-danger, #dc2626)">
                {{ __('Blocked — the following payments have already been touched:') }}
            </p>
            <table class="vt-table" style="margin-top:.25rem">
                <tbody>
                    @foreach ($preview['blockers'] as $b)
                        <tr>
                            <td>
                                {{ $b['type'] }} #{{ $b['id'] }} ({{ $b['year'] }})
                                @if ($b['belongs_to_next_year'])
                                    <span class="vt-code vt-small">{{ __('scheduled from :year', ['year' => $year]) }}</span>
                                @endif
                                <br>
                                <span class="vt-muted vt-small">
                                    {{ $b['status'] }}
                                    @if ($b['paid_date']) · {{ __('paid :date', ['date' => \Illuminate\Support\Carbon::parse($b['paid_date'])->format('d/m/Y')]) }} @endif
                                    @if ($b['reported']) · {{ __('customer-reported') }} @endif
                                </span>
                            </td>
                            <td class="vt-num">€ {{ number_format($b['paid_amount'] > 0 ? $b['paid_amount'] : $b['amount'], 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="vt-muted vt-small" style="margin-top:.5rem">{{ __('Unmark these as paid first if this year genuinely needs to be removed. Deleting is refused rather than silently dropping a paid payment.') }}</p>
        @endif
    @endif
</div>
