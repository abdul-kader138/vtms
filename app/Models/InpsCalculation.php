<?php

namespace App\Models;

use App\Enums\InpsCalculationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InpsCalculation extends Model
{
    protected $fillable = [
        'customer_id', 'tax_year', 'taxable_income', 'inps_rate', 'inps_amount',
        'discount_percentage', 'discount_amount', 'inps_amount_after_discount',
        'slab_details', 'calculation_method', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'taxable_income' => 'decimal:2',
            'inps_rate' => 'decimal:2',
            'inps_amount' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'inps_amount_after_discount' => 'decimal:2',
            'slab_details' => 'array',
            'calculation_method' => InpsCalculationMethod::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InpsPayment::class);
    }
}
