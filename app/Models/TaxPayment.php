<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\TaxPaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxPayment extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'paid_amount' => 0,
    ];

    protected $fillable = [
        'customer_id', 'tax_calculation_id', 'payment_type', 'tax_year',
        'status', 'amount', 'paid_amount', 'paid_date', 'uploaded_pdf', 'due_date',
    ];

    protected function casts(): array
    {
        return [
            'payment_type' => TaxPaymentType::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function taxCalculation(): BelongsTo
    {
        return $this->belongsTo(TaxCalculation::class);
    }
}
