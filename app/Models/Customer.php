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
        'name', 'company', 'vat_no', 'ateco_code', 'address', 'city', 'phone', 'email',
        'customer_type', 'coefficient_of_profitability', 'tax_rate', 'startup_rate_eligible',
        'business_start_date', 'inps_discount_eligible',
        'annual_revenue_limit', 'employee_cost_limit',
        'inail_ateco_code', 'inail_rate', 'inail_minimum_payment',
        'chamber_section', 'chamber_surcharge',
        'codice_fiscale', 'inps_office_code', 'inps_code', 'gs_other_pension', 'chamber_province',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'coefficient_of_profitability' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'business_start_date' => 'date',
            'inps_discount_eligible' => 'boolean',
            'startup_rate_eligible' => 'boolean',
            'annual_revenue_limit' => 'decimal:2',
            'employee_cost_limit' => 'decimal:2',
            'inail_rate' => 'decimal:2',
            'inail_minimum_payment' => 'decimal:2',
            'chamber_surcharge' => 'boolean',
            'gs_other_pension' => 'boolean',
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

            // Existing calculations and payment schedules (INPS installment
            // count, INAIL, Diritto Annuale) depend on the type they were
            // computed with — changing it afterwards silently invalidates them.
            if ($customer->exists && $customer->isDirty('customer_type') && $customer->getOriginal('customer_type') !== null && $customer->hasTaxCalculations()) {
                throw new RuntimeException('customer_type cannot be changed once tax calculations exist for this customer.');
            }
        });
    }

    public const STARTUP_RATE = 5.00;

    public const STANDARD_RATE = 15.00;

    public const STARTUP_YEARS = 5;

    /**
     * Regime Forfettario start-up rate: 5% for the start year and the four
     * after it, 15% from then on (L. 190/2014, c. 65). Customers not
     * flagged as eligible keep whatever rate was set manually.
     */
    public function taxRateFor(int $year): float
    {
        if (! $this->startup_rate_eligible || $this->business_start_date === null) {
            return (float) $this->tax_rate;
        }

        return $year < $this->business_start_date->year + self::STARTUP_YEARS ? self::STARTUP_RATE : self::STANDARD_RATE;
    }

    public function startupRateEndsAfter(): ?int
    {
        return $this->startup_rate_eligible && $this->business_start_date
            ? $this->business_start_date->year + self::STARTUP_YEARS - 1
            : null;
    }

    public function hasTaxCalculations(): bool
    {
        return $this->taxCalculations()->exists();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function incomeData(): HasMany
    {
        return $this->hasMany(IncomeData::class);
    }

    public function incomeImportBatches(): HasMany
    {
        return $this->hasMany(IncomeImportBatch::class);
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
