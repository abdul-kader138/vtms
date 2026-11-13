<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FatturaTraPrivatiCalculation extends Model
{
    protected $fillable = [
        'customer_id', 'tax_year', 'total_invoices', 'total_sales_amount',
        'payment_per_invoice', 'total_payment_amount', 'minimum_invoice_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_sales_amount' => 'decimal:2',
            'payment_per_invoice' => 'decimal:2',
            'total_payment_amount' => 'decimal:2',
            'minimum_invoice_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FatturaTraPrivatiPayment::class);
    }
}
