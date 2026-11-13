<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Tax\AnnualTaxReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates the "Conto Economico" (annual income statement) PDF — ported
 * from VTMS's Tax_calculations::annual_tax_report_pdf(). Gated the same way
 * as TaxPaymentReceiptController: a signed, expiring URL AND an
 * authenticated staff session.
 */
class AnnualTaxReportController
{
    public function __invoke(Request $request, Customer $customer, int $year, AnnualTaxReportService $service): Response
    {
        abort_unless($request->user()?->isStaff(), 403);

        try {
            $data = $service->build($customer, $year);
        } catch (RuntimeException) {
            abort(404, __('No tax calculation exists for :year yet.', ['year' => $year]));
        }

        return Pdf::loadView('pdf.tax.annual-report', $data)->download($service->filename($customer, $year));
    }
}
