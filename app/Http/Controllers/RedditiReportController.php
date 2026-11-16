<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Tax\RedditiReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates the draft "Redditi Persone Fisiche" PDF (Quadro LM/RR). Gated
 * the same way as AnnualTaxReportController: a signed, expiring URL AND an
 * authenticated staff session.
 */
class RedditiReportController
{
    public function __invoke(Request $request, Customer $customer, int $year, RedditiReportService $service): Response
    {
        abort_unless($request->user()?->isStaff(), 403);

        try {
            $data = $service->build($customer, $year);
        } catch (RuntimeException) {
            abort(404, __('No tax calculation exists for :year yet.', ['year' => $year]));
        }

        return Pdf::loadView('pdf.tax.redditi', $data)->download($service->filename($customer, $year));
    }
}
