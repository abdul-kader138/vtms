<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Conto Economico {{ $year }}</title>
    <style>
        @page {
            margin: 40mm 28mm 15mm 28mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12px;
            color: #000;
            line-height: 1.4;
            background: #fff;
            padding: 10px;
        }

        .top-right-info {
            text-align: right;
            font-size: 10px;
            margin-bottom: 20px;
            color: #000;
            line-height: 1.3;
        }

        .top-right-info div {
            margin-bottom: 2px;
        }

        .header-section {
            width: 100%;
            margin-bottom: 15px;
            text-align: center;
        }

        .business-name {
            font-size: 15px;
            font-weight: bold;
            color: #000;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .info-line {
            font-size: 11px;
            color: #000;
            margin-bottom: 2px;
            line-height: 1.3;
        }

        .tax-info {
            text-align: center;
            font-size: 11px;
            color: #000;
            margin-top: 6px;
            margin-bottom: 15px;
            line-height: 1.4;
        }

        .tax-info div {
            margin-bottom: 2px;
        }

        .period-section {
            text-align: center;
            font-size: 12px;
            color: #000;
            margin: 18px 0;
        }

        .report-title-section {
            width: 100%;
            margin: 20px 0 15px 0;
            display: table;
        }

        .report-title-left {
            display: table-cell;
            text-align: left;
            font-size: 13px;
            font-weight: bold;
        }

        .report-title-right {
            display: table-cell;
            text-align: right;
            font-size: 13px;
            font-weight: bold;
            text-decoration: underline;
        }

        .income-statement {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 11px;
        }

        .income-statement thead th {
            background-color: #e0e0e0;
            color: #000;
            padding: 7px 10px;
            font-size: 11px;
            font-weight: bold;
            text-align: left;
            border: none;
        }

        .income-statement tbody td {
            padding: 5px 10px;
            border: none;
            font-size: 11px;
            color: #000;
            vertical-align: top;
        }

        .income-statement tbody td.label {
            text-align: left;
            width: 75%;
        }

        .income-statement tbody td.value {
            text-align: right;
            width: 25%;
            font-weight: normal;
            border-bottom: 1px solid #000;
            padding-bottom: 8px;
        }

        .income-statement tbody tr.total-row td {
            background-color: #e0e0e0;
            font-weight: normal;
            padding: 7px 10px;
        }

        .income-statement tbody tr.total-row td.value {
            border-bottom: none;
            padding-bottom: 7px;
        }

        .income-statement tbody tr.net-income-row td {
            background-color: #e0e0e0;
            font-weight: bold;
            padding: 8px 10px;
            font-size: 11px;
        }

        .income-statement tbody tr.net-income-row td.value {
            border-bottom: none;
            padding-bottom: 8px;
        }

        .footer-section {
            width: 100%;
            margin-top: 35px;
        }

        .sig-title {
            font-size: 11px;
            color: #000;
            margin-bottom: 4px;
        }

        .owner-name {
            font-size: 11px;
            font-weight: normal;
            color: #000;
            margin-top: 3px;
            text-transform: uppercase;
        }

        .currency {
            font-family: "Times New Roman", Times, serif;
            white-space: nowrap;
        }
    </style>
</head>
<body>

    @if (!empty($vat_number))
        <div class="top-right-info">
            <div>P.IVA: {{ $vat_number }}</div>
        </div>
    @endif

    <div class="header-section">
        <div class="business-name">{{ \Illuminate\Support\Str::upper($customer->company ?: $customer->name) }}</div>

        @if (!empty($customer->address))
            <div class="info-line">{{ $customer->address }}</div>
        @endif

        @if (!empty($customer->city))
            <div class="info-line">{{ \Illuminate\Support\Str::upper($customer->city) }}</div>
        @endif
    </div>

    <div class="tax-info">
        <div>Contribuente in {{ $tax_regime === 'regime_forfettario' ? 'regime forfettario' : $tax_regime }}</div>
        @if (!empty($activity_description))
            <div>attività di {{ $activity_description }}</div>
        @endif
    </div>

    <div class="period-section">
        Situazione economica dal {{ $period_start }} al {{ $period_end }}
    </div>

    <div class="report-title-section">
        <div class="report-title-left"><strong>Conto Economico</strong></div>
        <div class="report-title-right"><strong>{{ $period_end }}</strong></div>
    </div>

    <table class="income-statement">
        <thead>
            <tr><th colspan="2">A) <strong>Valore della produzione</strong></th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">Ricavi delle prestazioni</td>
                <td class="value currency">€ {{ number_format($total_sales, 2, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="label">Totale valore della produzione</td>
                <td class="value currency">€ {{ number_format($total_sales, 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <table class="income-statement">
        <thead>
            <tr><th colspan="2">B) <strong>Costi della produzione</strong></th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="label">
                    @if (!empty($ateco_code))
                        In base al codice ateco {{ $ateco_code }} redditività {{ number_format($coefficient, 0) }}%
                    @else
                        In base alla redditività {{ number_format($coefficient, 0) }}%
                    @endif
                </td>
                <td class="value currency">€ {{ number_format($costs, 2, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="label">Totale costi della produzione</td>
                <td class="value currency">€ {{ number_format($costs, 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    @if ($inps_deducted > 0)
        <table class="income-statement">
            <tbody>
                <tr>
                    <td class="label">Reddito lordo</td>
                    <td class="value currency">€ {{ number_format($gross_income, 2, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="label">Contributi previdenziali versati nell'anno (dedotti)</td>
                    <td class="value currency">− € {{ number_format($inps_deducted, 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="income-statement">
        <tbody>
            <tr class="net-income-row">
                <td class="label"><strong>Reddito netto</strong></td>
                <td class="value currency"><strong>€ {{ number_format($net_income, 2, ',', '.') }}</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="footer-section">
        <div class="sig-title">Titolare</div>
        <div class="owner-name">{{ \Illuminate\Support\Str::upper($customer->name) }}</div>
    </div>

</body>
</html>
