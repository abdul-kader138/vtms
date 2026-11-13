<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IncomeData;
use App\Models\TaxCalculation;
use App\Services\Tax\AnnualTaxReportService;
use App\Services\Tax\PaymentRegistry;
use App\Services\Tax\TaxOverviewService;
use App\Support\Tax\AtecoGroup;
use App\Support\Tax\F24Code;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer-portal view of the logged-in user's own linked customer — the
 * equivalent of VTMS's customer dashboard (Main::index). Read-only: only
 * staff can recalculate or record payments.
 */
class TaxController extends Controller
{
    public function __construct(private readonly TaxOverviewService $overviewService) {}

    #[OA\Get(
        path: '/api/v1/tax/years',
        summary: 'Tax years available for the linked customer',
        security: [['sanctumSession' => []]],
        tags: ['Tax'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'No customer linked to this account'),
        ],
    )]
    public function years(Request $request): JsonResponse
    {
        $customer = $this->customerFor($request);

        $years = TaxCalculation::where('customer_id', $customer->id)->pluck('tax_year')
            ->merge(IncomeData::where('customer_id', $customer->id)->distinct()->pluck('tax_year'))
            ->merge(IncomeData::where('customer_id', $customer->id)->whereNotNull('income_year')->distinct()->pluck('income_year'))
            ->push(now()->year)
            ->map(fn ($y) => (int) $y)
            ->unique()
            ->sortDesc()
            ->values();

        $latestCalculated = TaxCalculation::where('customer_id', $customer->id)->max('tax_year');

        return response()->json([
            'customer' => $this->customerData($customer),
            'years' => $years,
            'default_year' => $latestCalculated !== null ? (int) $latestCalculated : now()->year,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/tax/{year}',
        summary: 'Tax calculations, payment schedule, live estimate and prediction for one year',
        security: [['sanctumSession' => []]],
        tags: ['Tax'],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 404, description: 'No customer linked to this account'),
        ],
    )]
    public function show(Request $request, int $year): JsonResponse
    {
        $customer = $this->customerFor($request);
        $overview = $this->overviewService->build($customer, $year);

        $payments = [];
        foreach ($overview['payments'] as $type => $collection) {
            foreach ($collection as $payment) {
                $payments[] = $this->paymentData($type, $payment);
            }
        }
        usort($payments, fn ($a, $b) => strcmp((string) $a['due_date'], (string) $b['due_date']));

        $tax = $overview['tax'];
        $inps = $overview['inps'];
        $inail = $overview['inail'];
        $fattura = $overview['fattura_tra_privati'];

        return response()->json([
            'year' => $year,
            'customer' => $this->customerData($customer),
            'calculated' => $tax !== null,
            'tax' => $tax ? $tax->only(['total_sales', 'taxable_income', 'inps_deducted', 'coefficient_used', 'tax_rate_used', 'tax_due', 'advance_payments_made', 'balance_payment', 'next_year_advance_base', 'rules']) + ['gross_income' => $tax->grossIncome()] : null,
            'tax_rate_for_year' => $customer->taxRateFor($year),
            'inps' => $inps?->only(['taxable_income', 'inps_rate', 'inps_amount', 'discount_percentage', 'discount_amount', 'inps_amount_after_discount', 'slab_details']),
            'inail' => $inail?->only(['taxable_income', 'inail_rate', 'inail_calculated_amount', 'inail_minimum_payment', 'inail_final_amount', 'ateco_code']),
            'fattura_tra_privati' => $fattura?->only(['total_invoices', 'total_sales_amount', 'payment_per_invoice', 'total_payment_amount', 'minimum_invoice_amount']),
            'previous_year_inps' => $overview['previous_year_inps'],
            'estimate' => $overview['estimate'],
            'prediction' => $overview['prediction'],
            'summary' => $overview['summary'],
            'warnings' => $overview['warnings'],
            'payments' => $payments,
            'annual_report_url' => $tax ? route('api.v1.tax.annual-report', ['year' => $year]) : null,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/tax/{year}/annual-report',
        summary: 'Download the Conto Economico (annual income statement) PDF',
        security: [['sanctumSession' => []]],
        tags: ['Tax'],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Not calculated for this year'),
        ],
    )]
    public function annualReport(Request $request, int $year, AnnualTaxReportService $service): Response
    {
        $customer = $this->customerFor($request);

        try {
            $data = $service->build($customer, $year);
        } catch (RuntimeException) {
            abort(404, __('No tax calculation exists for :year yet.', ['year' => $year]));
        }

        return Pdf::loadView('pdf.tax.annual-report', $data)->download($service->filename($customer, $year));
    }

    #[OA\Get(
        path: '/api/v1/tax/payments/{type}/{id}/receipt',
        summary: 'Download the receipt uploaded for one of the linked customer\'s payments',
        security: [['sanctumSession' => []]],
        tags: ['Tax'],
        responses: [new OA\Response(response: 200, description: 'PDF'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function receipt(Request $request, string $type, int $id): Response
    {
        $customer = $this->customerFor($request);

        $payment = PaymentRegistry::find($type, $id, $customer->id);
        abort_if($payment === null || blank($payment->uploaded_pdf), 404);
        abort_unless(Storage::disk('local')->exists($payment->uploaded_pdf), 404);

        return Storage::disk('local')->download($payment->uploaded_pdf);
    }

    private function customerFor(Request $request): Customer
    {
        $customer = $request->user()->customer;

        // A distinct code so the portal can show its "waiting to be linked"
        // screen instead of a generic error.
        if ($customer === null) {
            throw new HttpResponseException(response()->json([
                'message' => __('No customer record is linked to this account yet. Please contact your accountant.'),
                'code' => 'not_linked',
                'user' => ['first_name' => $request->user()->first_name, 'email' => $request->user()->email],
            ], 404));
        }

        return $customer;
    }

    private function customerData(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'company' => $customer->company,
            'customer_type' => $customer->customer_type?->value,
            'customer_type_label' => $customer->customer_type?->label(),
            'coefficient_of_profitability' => $customer->coefficient_of_profitability,
            'tax_rate' => $customer->taxRateFor(now()->year),
            'ateco_code' => $customer->ateco_code,
            'activity' => AtecoGroup::forAtecoCode($customer->ateco_code)?->label(),
            'startup_rate_until' => $customer->startupRateEndsAfter(),
            'revenue_limit' => (float) ($customer->annual_revenue_limit ?? 85000),
        ];
    }

    private function paymentData(string $type, Model $payment): array
    {
        return [
            'id' => $payment->id,
            'type' => $type,
            'description' => PaymentRegistry::describe($type, $payment),
            'f24_code' => F24Code::for($type, $payment),
            'f24_section' => F24Code::section($type),
            'due_date' => $payment->due_date?->toDateString(),
            'amount' => (float) $payment->amount,
            'paid_amount' => (float) $payment->paid_amount,
            'outstanding' => round(max(0, (float) $payment->amount - (float) $payment->paid_amount), 2),
            'paid_date' => $payment->paid_date?->toDateString(),
            'status' => $payment->status?->value,
            'effective_status' => TaxOverviewService::effectiveStatus($payment),
            'receipt_url' => filled($payment->uploaded_pdf)
                ? route('api.v1.tax.receipt', ['type' => $type, 'id' => $payment->id])
                : null,
        ];
    }
}
