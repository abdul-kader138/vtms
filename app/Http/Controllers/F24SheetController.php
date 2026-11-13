<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Tax\F24SheetService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/** F24 payment sheet PDF — signed, expiring URL plus a staff session, like the annual report. */
class F24SheetController
{
    public function __invoke(Request $request, Customer $customer, string $date, F24SheetService $service): Response
    {
        abort_unless($request->user()?->isStaff(), 403);

        $request->validate(['pay_on' => ['nullable', 'date_format:Y-m-d']]);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);

        $due = Carbon::createFromFormat('!Y-m-d', $date);
        $payOn = $request->filled('pay_on') ? Carbon::createFromFormat('!Y-m-d', $request->query('pay_on')) : today();
        if ($payOn->lt($due)) {
            $payOn = $due->copy();
        }

        return Pdf::loadView('pdf.tax.f24-sheet', $service->build($customer, $due, $payOn))
            ->download($service->filename($customer, $due));
    }
}
