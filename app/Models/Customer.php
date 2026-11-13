<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Customer extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'company', 'vat_no', 'address', 'city', 'phone', 'email',
        'customer_type', 'coefficient_of_profitability', 'tax_rate',
        'business_start_date', 'inps_discount_eligible',
        'annual_revenue_limit', 'employee_cost_limit',
        'inail_ateco_code', 'inail_rate', 'inail_minimum_payment',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'coefficient_of_profitability' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'business_start_date' => 'date',
            'inps_discount_eligible' => 'boolean',
            'annual_revenue_limit' => 'decimal:2',
            'employee_cost_limit' => 'decimal:2',
            'inail_rate' => 'decimal:2',
            'inail_minimum_payment' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Business rule confirmed in the plan: the INPS discount only ever
        // applies to Commercianti/Artigiani contributor categories. Enforced
        // here (not just in a future Filament form) so it can never be
        // violated via tinker/seeders/imports either.
        static::saving(function (Customer $customer) {
            if ($customer->inps_discount_eligible && ! in_array($customer->customer_type, [CustomerType::Commercianti, CustomerType::Artigiani], true)) {
                throw new RuntimeException('inps_discount_eligible can only be true for Commercianti or Artigiani customer types.');
            }
        });
    }

    public function incomeData(): HasMany
    {
        return $this->hasMany(IncomeData::class);
    }

    public function taxCalculations(): HasMany
    {
        return $this->hasMany(TaxCalculation::class);
    }

    public function taxPayments(): HasMany
    {
        return $this->hasMany(TaxPayment::class);
    }

    public function inpsCalculations(): HasMany
    {
        return $this->hasMany(InpsCalculation::class);
    }

    public function inpsPayments(): HasMany
    {
        return $this->hasMany(InpsPayment::class);
    }

    public function inailCalculations(): HasMany
    {
        return $this->hasMany(InailCalculation::class);
    }

    public function inailPayments(): HasMany
    {
        return $this->hasMany(InailPayment::class);
    }

    public function dirittoAnnualePayments(): HasMany
    {
        return $this->hasMany(DirittoAnnualePayment::class);
    }

    public function fatturaTraPrivatiCalculations(): HasMany
    {
        return $this->hasMany(FatturaTraPrivatiCalculation::class);
    }

    public function fatturaTraPrivatiPayments(): HasMany
    {
        return $this->hasMany(FatturaTraPrivatiPayment::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('customer');
    }
}
