<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TaxCalculation extends Model
{
    use LogsActivity;

    protected $fillable = [
        'customer_id', 'tax_year', 'total_sales', 'gross_income', 'inps_deducted', 'taxable_income',
        'coefficient_used', 'tax_rate_used', 'tax_due',
        'advance_payments_made', 'balance_payment', 'next_year_advance_base', 'rules',
        'is_manually_adjusted', 'adjusted_by', 'adjusted_at', 'adjustment_reason',
        'employee_cost',
    ];

    protected function casts(): array
    {
        return [
            'total_sales' => 'decimal:2',
            'gross_income' => 'decimal:2',
            'inps_deducted' => 'decimal:2',
            'taxable_income' => 'decimal:2',
            'coefficient_used' => 'decimal:2',
            'tax_rate_used' => 'decimal:2',
            'tax_due' => 'decimal:2',
            'advance_payments_made' => 'decimal:2',
            'balance_payment' => 'decimal:2',
            'next_year_advance_base' => 'decimal:2',
            'employee_cost' => 'decimal:2',
            'is_manually_adjusted' => 'boolean',
            'adjusted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tax_calculation');
    }

    /**
     * Income before the INPS deduction — the base INPS and INAIL themselves
     * are calculated on (contributions are never deducted from their own base).
     */
    public function grossIncome(): float
    {
        return (float) ($this->gross_income ?? $this->taxable_income);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TaxPayment::class);
    }
}
