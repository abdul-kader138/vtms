<?php

namespace App\Services\Tax;

use App\Enums\PaymentStatus;
use App\Support\Tax\F24Code;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One flat, date-ordered list of payments across all five payment tables
 * and all customers — the accountant's "scadenzario".
 */
class PaymentCalendarService
{
    /**
     * @param  list<string>|null  $types  payment type slugs, null = all
     * @param  string|null  $status  pending|overdue|paid|cancelled (effective status), or 'open' for pending+overdue
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?array $types = null, ?string $status = null, ?int $customerId = null, ?string $search = null): Collection
    {
        $rows = collect();

        foreach (PaymentRegistry::MODELS as $type => $model) {
            if ($types !== null && ! in_array($type, $types, true)) {
                continue;
            }

            $query = $model::query()->with('customer');

            if ($from) {
                $query->whereDate('due_date', '>=', $from);
            }
            if ($to) {
                $query->whereDate('due_date', '<=', $to);
            }
            if ($customerId) {
                $query->where('customer_id', $customerId);
            }
            if ($search) {
                $query->whereHas('customer', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('company', 'like', "%{$search}%"));
            }
            // Narrow in SQL where we can; effective status is refined below.
            if (in_array($status, ['open', 'pending', 'overdue'], true)) {
                $query->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Overdue]);
            } elseif ($status !== null) {
                $query->where('status', $status);
            }

            foreach ($query->get() as $payment) {
                if ($payment->customer === null) {
                    continue; // soft-deleted customer
                }

                $rows->push($this->entry($type, $payment));
            }
        }

        if (in_array($status, ['pending', 'overdue'], true)) {
            $rows = $rows->where('status', $status);
        }

        return $rows->sortBy([['due_date', 'asc'], ['customer_name', 'asc']])->values();
    }

    public function entry(string $type, $payment): array
    {
        $amount = (float) $payment->amount;
        $paid = (float) $payment->paid_amount;

        return [
            'key' => "{$type}-{$payment->id}",
            'type' => $type,
            'id' => $payment->id,
            'payment' => $payment,
            'customer' => $payment->customer,
            'customer_name' => $payment->customer->company ?: $payment->customer->name,
            'description' => PaymentRegistry::describe($type, $payment),
            'f24' => F24Code::for($type, $payment),
            'due_date' => $payment->due_date,
            'amount' => $amount,
            'paid_amount' => $paid,
            'outstanding' => round(max(0, $amount - $paid), 2),
            'status' => TaxOverviewService::effectiveStatus($payment),
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $entries */
    public function totals(Collection $entries): array
    {
        $open = $entries->whereIn('status', ['pending', 'overdue']);

        return [
            'count' => $entries->count(),
            'outstanding' => round($open->sum('outstanding'), 2),
            'overdue' => round($entries->where('status', 'overdue')->sum('outstanding'), 2),
            'overdue_count' => $entries->where('status', 'overdue')->count(),
            'paid' => round($entries->sum('paid_amount'), 2),
        ];
    }
}
