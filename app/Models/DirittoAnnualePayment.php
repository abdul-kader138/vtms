<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirittoAnnualePayment extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'paid_amount' => 0,
    ];

    protected $fillable = [
        'customer_id', 'diritto_annuale_bracket_id', 'payment_year',
        'status', 'amount', 'paid_amount', 'paid_date', 'uploaded_pdf', 'due_date',
    ];

    protected function casts(): array
    {
        return [
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

    public function bracket(): BelongsTo
    {
        return $this->belongsTo(DirittoAnnualeBracket::class, 'diritto_annuale_bracket_id');
    }
}
