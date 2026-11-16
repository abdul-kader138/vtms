<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Redditi PF — Quadro LM/RR {{ $year }}</title>
    <style>
        @page { margin: 25mm 20mm; }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12px;
            color: #000;
            line-height: 1.4;
        }

        .header-section { text-align: center; margin-bottom: 15px; }
        .business-name { font-size: 15px; font-weight: bold; text-transform: uppercase; margin-bottom: 4px; }
        .info-line { font-size: 11px; margin-bottom: 2px; }

        .disclaimer {
            border: 1px solid #000;
            padding: 8px 10px;
            font-size: 10px;
            margin: 14px 0 18px;
        }

        .quadro-title {
            font-size: 13px;
            font-weight: bold;
            text-decoration: underline;
            margin: 18px 0 8px;
        }

        .quadro-subtitle { font-size: 11px; font-style: italic; margin-bottom: 8px; }

        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11px; }
        table.lines td { padding: 5px 8px; border-bottom: 1px solid #ccc; vertical-align: top; }
        table.lines td.ref { width: 10%; font-weight: bold; }
        table.lines td.label { width: 55%; }
        table.lines td.value { width: 35%; text-align: right; font-variant-numeric: tabular-nums; }
        table.lines tr.total td { border-top: 1.5px solid #000; border-bottom: none; font-weight: bold; background: #efefef; }

        .footer-section { margin-top: 30px; font-size: 11px; }
    </style>
</head>
<body>

    <div class="header-section">
        <div class="business-name">{{ \Illuminate\Support\Str::upper($customer->company ?: $customer->name) }}</div>
        @if ($customer->vat_no)
            <div class="info-line">P.IVA: {{ $customer->vat_no }}</div>
        @endif
        @if ($customer->codice_fiscale)
            <div class="info-line">Codice fiscale: {{ $customer->codice_fiscale }}</div>
        @endif
    </div>

    <div class="quadro-title" style="text-align:center">Redditi Persone Fisiche {{ $year + 1 }} — anno d'imposta {{ $year }}</div>
    <div class="quadro-subtitle" style="text-align:center">Bozza di lavoro — Quadro LM e Quadro RR</div>

    <div class="disclaimer">
        Questo documento è una <strong>bozza di calcolo</strong>, non la dichiarazione. La dichiarazione dei redditi va
        presentata esclusivamente tramite RedditiOnline o un intermediario abilitato. I numeri di rigo qui riportati
        vanno verificati sul modello Redditi PF in vigore per l'anno di presentazione, che può differire da questo.
    </div>

    <div class="quadro-title">Quadro LM — Reddito di impresa/lavoro autonomo (regime forfettario)</div>
    <table class="lines">
        <tr><td class="ref">LM22</td><td class="label">Ricavi/compensi percepiti nell'anno{{ $lm['ateco_code'] ? ' (ATECO '.$lm['ateco_code'].')' : '' }}</td><td class="value">€ {{ number_format($lm['total_sales'], 2, ',', '.') }}</td></tr>
        <tr><td class="ref">LM34</td><td class="label">Coefficiente di redditività applicato</td><td class="value">{{ number_format($lm['coefficient'], 0) }}%</td></tr>
        <tr><td class="ref">LM35</td><td class="label">Reddito lordo (ricavi × coefficiente)</td><td class="value">€ {{ number_format($lm['gross_income'], 2, ',', '.') }}</td></tr>
        @if ($lm['inps_deducted'] > 0)
            <tr><td class="ref">LM36</td><td class="label">Contributi previdenziali versati nell'anno (deducibili)</td><td class="value">− € {{ number_format($lm['inps_deducted'], 2, ',', '.') }}</td></tr>
        @endif
        <tr class="total"><td class="ref">LM37</td><td class="label">Reddito imponibile</td><td class="value">€ {{ number_format($lm['taxable_income'], 2, ',', '.') }}</td></tr>
        <tr><td class="ref">LM38/39</td><td class="label">Aliquota imposta sostitutiva applicata</td><td class="value">{{ rtrim(rtrim(number_format($lm['tax_rate'], 2), '0'), '.') }}%</td></tr>
        <tr class="total"><td class="ref">LM41</td><td class="label">Imposta sostitutiva dovuta</td><td class="value">€ {{ number_format($lm['tax_due'], 2, ',', '.') }}</td></tr>
    </table>

    @if ($rr)
        <div class="quadro-title">Quadro RR — Contributi IVS dovuti ({{ $customer->customer_type?->label() }})</div>
        <table class="lines">
            <tr><td class="ref">RR{{ $rr['section'] }}</td><td class="label">Sezione</td><td class="value">{{ $rr['section'] === 'II' ? 'II — Gestione Separata' : 'I — Artigiani/Commercianti' }}</td></tr>
            <tr><td class="ref">RR3</td><td class="label">Reddito imponibile ai fini contributivi</td><td class="value">€ {{ number_format($rr['taxable_income'], 2, ',', '.') }}</td></tr>
            @if ($rr['fixed_amount'] !== null)
                <tr><td class="ref">RR13</td><td class="label">Contributi fissi sul minimale</td><td class="value">€ {{ number_format($rr['fixed_amount'], 2, ',', '.') }}</td></tr>
                <tr><td class="ref">RR14</td><td class="label">Contributi sulla quota eccedente il minimale (aliquota {{ $rr['rate'] }}%)</td><td class="value">€ {{ number_format($rr['variable_amount'], 2, ',', '.') }}</td></tr>
            @else
                <tr><td class="ref">RR5</td><td class="label">Aliquota applicata</td><td class="value">{{ $rr['rate'] }}%</td></tr>
            @endif
            @if ($rr['discount_amount'] > 0)
                <tr><td class="ref">—</td><td class="label">Riduzione contributiva ({{ $rr['discount_percentage'] }}%)</td><td class="value">− € {{ number_format($rr['discount_amount'], 2, ',', '.') }}</td></tr>
            @endif
            <tr class="total"><td class="ref">RR16</td><td class="label">Contributi dovuti per l'anno</td><td class="value">€ {{ number_format($rr['amount_due'], 2, ',', '.') }}</td></tr>
        </table>
    @endif

    <div class="footer-section">
        Documento generato automaticamente il {{ now()->format('d/m/Y') }} — da verificare con il proprio commercialista
        prima di presentare la dichiarazione.
    </div>

</body>
</html>
