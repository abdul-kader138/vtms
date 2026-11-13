<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InpsPayment extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'paid_amount' => 0,
    ];

    protected $fillable = [
        'customer_id', 'inps_calculation_id', 'installment_number', 'payment_year',
        'payment_method', 'payment_reference', 'notes',
        'status', 'amount', 'paid_amount', 'paid_date', 'uploaded_pdf', 'due_date',
        'reported_paid_at', 'reported_paid_date', 'reported_paid_amount',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_date' => 'date',
            'reported_paid_at' => 'datetime',
            'reported_paid_date' => 'date',
            'reported_paid_amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function inpsCalculation(): BelongsTo
    {
        return $this->belongsTo(InpsCalculation::class);
    }
}
