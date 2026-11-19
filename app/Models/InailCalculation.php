<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class InailCalculation extends Model
{
    use LogsActivity;

    protected $fillable = [
        'customer_id', 'tax_year', 'taxable_income', 'inail_rate',
        'inail_calculated_amount', 'inail_minimum_payment', 'inail_final_amount', 'ateco_code',
        'is_manually_adjusted', 'adjusted_by', 'adjusted_at', 'adjustment_reason',
    ];

    protected function casts(): array
    {
        return [
            'taxable_income' => 'decimal:2',
            'inail_rate' => 'decimal:2',
            'inail_calculated_amount' => 'decimal:2',
            'inail_minimum_payment' => 'decimal:2',
            'inail_final_amount' => 'decimal:2',
            'is_manually_adjusted' => 'boolean',
            'adjusted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InailPayment::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('inail_calculation');
    }
}
