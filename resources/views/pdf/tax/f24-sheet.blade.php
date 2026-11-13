@php
    $eur = fn ($v) => number_format((float) $v, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>F24 {{ $due_date->format('d/m/Y') }}</title>
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        .muted { color: #666; }
        .head { width: 100%; margin-bottom: 14px; }
        .head td { vertical-align: top; }
        .box { border: 1px solid #ccc; border-radius: 4px; padding: 8px 10px; }
        .late { background: #fff4e5; border-color: #f0b46b; margin-bottom: 12px; }
        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #111; text-transform: uppercase; letter-spacing: .03em; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th { text-align: left; font-size: 9px; text-transform: uppercase; color: #555; border-bottom: 1px solid #999; padding: 4px; }
        table.lines td { padding: 5px 4px; border-bottom: 1px solid #e3e3e3; }
        .num { text-align: right; white-space: nowrap; }
        .code { font-family: DejaVu Sans Mono, monospace; font-weight: bold; font-size: 11.5px; }
        .sub td { font-weight: bold; border-bottom: 0; }
        .total { margin-top: 18px; font-size: 14px; text-align: right; }
        .total strong { font-size: 18px; }
        .fill { color: #999; }
        ul { margin: 6px 0 0 16px; padding: 0; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>{{ __('F24 payment sheet') }}</h1>
                <div class="muted">{{ __('Deadline :date', ['date' => $due_date->format('d/m/Y')]) }} · {{ __('to pay on :date', ['date' => $pay_on->format('d/m/Y')]) }}</div>
            </td>
            <td style="text-align:right">
                <strong>{{ $customer->company ?: $customer->name }}</strong><br>
                @if ($customer->company)<span class="muted">{{ $customer->name }}</span><br>@endif
                @if ($customer->codice_fiscale)<span class="muted">{{ __('Tax code') }} {{ strtoupper($customer->codice_fiscale) }}</span><br>@endif
                @if ($customer->vat_no)<span class="muted">{{ __('VAT') }} {{ $customer->vat_no }}</span>@endif
            </td>
        </tr>
    </table>

    @if ($late_days > 0)
        <div class="box late">
            <strong>{{ __('Paid :n days late', ['n' => $late_days]) }}</strong> —
            {{ __('the interest and penalty lines below settle it with ravvedimento operoso. Pay on the date shown, or regenerate the sheet for another date.') }}
        </div>
    @endif

    @forelse ($sections as $section)
        <h2>{{ __('Section') }}: {{ $section['name'] }}</h2>
        @php($first = $section['lines'][0] ?? [])
        @if (! empty($first['office']) || ! empty($first['account']))
            <p class="muted" style="margin:0 0 6px">
                @if (! empty($first['office'])){{ $section['name'] === __('INPS') ? __('Codice sede') : __('Codice ente') }}: <strong class="code">{{ $first['office'] }}</strong>@endif
                @if (! empty($first['account'])) &nbsp;·&nbsp; {{ __('Codice INPS') }}: <strong class="code">{{ $first['account'] }}</strong>@endif
            </p>
        @endif
        <table class="lines">
            <thead>
                <tr>
                    <th>{{ __('Code') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th>{{ $section['name'] === __('INPS') ? __('Period') : __('Reference year') }}</th>
                    <th class="num">{{ __('Amount due') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($section['lines'] as $line)
                    <tr>
                        <td class="code">{{ $line['code'] ?? '—' }}</td>
                        <td>{{ $line['description'] }}</td>
                        <td>{{ $line['period'] ?? $line['year'] ?? '' }}</td>
                        <td class="num">€ {{ $eur($line['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="sub">
                    <td colspan="3" class="num">{{ __('Section total') }}</td>
                    <td class="num">€ {{ $eur($section['total']) }}</td>
                </tr>
            </tbody>
        </table>
    @empty
        <p>{{ __('Nothing left to pay for this deadline.') }}</p>
    @endforelse

    <div class="total">{{ __('Total to pay') }}: <strong>€ {{ $eur($total) }}</strong></div>

    @if ($notes !== [])
        <div class="box" style="margin-top:16px">
            <strong>{{ __('Notes') }}</strong>
            <ul>
                @foreach ($notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="muted" style="margin-top:20px;font-size:9px">
        {{ __('Prepared on :date. Amounts are rounded to the cent; check them against the F24 before paying.', ['date' => now()->format('d/m/Y')]) }}
    </p>
</body>
</html>
