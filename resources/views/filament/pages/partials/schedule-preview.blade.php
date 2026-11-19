<div style="font-size:.875rem">
    @if (empty($rows))
        <p class="vt-muted">{{ __('Nothing to schedule yet.') }}</p>
    @else
        <table class="vt-table">
            <thead>
                <tr>
                    <th>{{ __('Payment') }}</th>
                    <th>{{ __('Due date') }}</th>
                    <th class="vt-num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td>{{ $row['due_date']?->format('d/m/Y') ?? __('varies') }}</td>
                        <td class="vt-num">€ {{ number_format($row['amount'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr style="font-weight:600">
                    <td colspan="2">{{ __('Total') }}</td>
                    <td class="vt-num">€ {{ number_format(collect($rows)->sum('amount'), 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
        <p class="vt-muted vt-small" style="margin-top:.5rem">{{ __('Provisional — recalculated live from the figures above. The real INAIL/stamp-duty quarterly split is applied only after saving.') }}</p>
    @endif
</div>
