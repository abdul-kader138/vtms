<div style="font-size:.875rem">
    @if (! $r['applicable'])
        <p class="vt-muted">{{ $r['reason'] ?? __('Not late on this date — no interest or penalty.') }}</p>
    @else
        <table class="vt-table">
            <tbody>
                <tr><td>{{ __('Amount still due') }} <span class="vt-muted vt-small">({{ __('due :date', ['date' => $payment->due_date->format('d/m/Y')]) }})</span></td><td class="vt-num">{{ $eur($outstanding) }}</td></tr>
                <tr><td>{{ __('Interest') }} <span class="vt-code">{{ $r['interest_code'] }}</span> <span class="vt-muted vt-small">· {{ trans_choice('{1} 1 day|[2,*] :count days', $r['days']) }}</span></td><td class="vt-num">{{ $eur($r['interest']) }}</td></tr>
                <tr><td>{{ __('Penalty') }} <span class="vt-code">{{ $r['sanction_code'] }}</span> <span class="vt-muted vt-small">· {{ number_format($r['sanction_percent'], 3, ',', '.') }}% ({{ __('reduced :r', ['r' => $r['reduction']]) }})</span></td><td class="vt-num">{{ $eur($r['sanction']) }}</td></tr>
                <tr><td style="font-weight:600">{{ __('Total to pay') }}</td><td class="vt-num" style="font-weight:600">{{ $eur($r['total']) }}</td></tr>
            </tbody>
        </table>
        <p class="vt-muted vt-small" style="margin-top:.5rem">{{ __('The penalty grows at 30 days, 90 days, one year and two years — paying sooner is cheaper.') }}</p>
    @endif
</div>
