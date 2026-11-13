<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Tax\PaymentRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a payment receipt from the private `local` disk. Gated by the
 * `signed` route middleware (the link embeds an expiring signature, see
 * payment-table.blade.php) AND an authenticated staff session — either
 * alone would be weaker than VTMS's own behavior (receipts were never
 * public there either).
 */
class TaxPaymentReceiptController
{
    public function __invoke(Request $request, Customer $customer, string $type, int $id): StreamedResponse
    {
        abort_unless($request->user()?->isStaff(), 403);

        $payment = PaymentRegistry::find($type, $id, $customer->id);
        abort_if($payment === null, 404);
        abort_if(blank($payment->uploaded_pdf), 404);
        abort_unless(Storage::disk('local')->exists($payment->uploaded_pdf), 404);

        return Storage::disk('local')->download($payment->uploaded_pdf);
    }
}
