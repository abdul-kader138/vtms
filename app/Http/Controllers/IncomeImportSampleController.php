<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Tax\Import\SampleFileService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sample import files (staff only); ?customer= fills in that customer's VAT number. */
class IncomeImportSampleController
{
    public function __invoke(Request $request, string $format, string $ext, SampleFileService $samples): Response
    {
        abort_unless($request->user()?->isStaff(), 403);
        abort_unless($samples->supports($format, $ext), 404);

        $customer = $request->filled('customer') ? Customer::findOrFail((int) $request->query('customer')) : null;

        return $samples->download($format, $ext, $customer);
    }
}
