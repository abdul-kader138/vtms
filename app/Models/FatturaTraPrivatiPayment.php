<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FatturaTraPrivatiPayment extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'paid_amount' => 0,
    ];

    protected $fillable = [
        'customer_id', 'fattura_tra_privati_calculation_id', 'payment_year', 'quarter',
        'status', 'amount', 'paid_amount', 'paid_date', 'uploaded_pdf', 'due_date',
        'reported_paid_at', 'reported_paid_date', 'reported_paid_amount',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'quarter' => 'integer',
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

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(FatturaTraPrivatiCalculation::class, 'fattura_tra_privati_calculation_id');
    }
}
