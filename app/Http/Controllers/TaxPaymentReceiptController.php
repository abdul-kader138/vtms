<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DirittoAnnualePayment;
use App\Models\FatturaTraPrivatiPayment;
use App\Models\InailPayment;
use App\Models\InpsPayment;
use App\Models\TaxPayment;
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
    private const MODELS = [
        'tax' => TaxPayment::class,
        'inps' => InpsPayment::class,
        'inail' => InailPayment::class,
        'diritto_annuale' => DirittoAnnualePayment::class,
        'fattura_tra_privati' => FatturaTraPrivatiPayment::class,
    ];

    public function __invoke(Request $request, Customer $customer, string $type, int $id): StreamedResponse
    {
        abort_unless($request->user()?->isStaff(), 403);

        $modelClass = self::MODELS[$type] ?? null;
        abort_if($modelClass === null, 404);

        $payment = $modelClass::findOrFail($id);
        abort_unless($payment->customer_id === $customer->id, 404);
        abort_if(blank($payment->uploaded_pdf), 404);
        abort_unless(Storage::disk('local')->exists($payment->uploaded_pdf), 404);

        return Storage::disk('local')->download($payment->uploaded_pdf);
    }
}
