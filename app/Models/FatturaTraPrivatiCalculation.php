<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class FatturaTraPrivatiCalculation extends Model
{
    use LogsActivity;

    protected $fillable = [
        'customer_id', 'tax_year', 'total_invoices', 'total_sales_amount',
        'payment_per_invoice', 'total_payment_amount', 'minimum_invoice_amount',
        'is_manually_adjusted', 'adjusted_by', 'adjusted_at', 'adjustment_reason',
    ];

    protected function casts(): array
    {
        return [
            'total_sales_amount' => 'decimal:2',
            'payment_per_invoice' => 'decimal:2',
            'total_payment_amount' => 'decimal:2',
            'minimum_invoice_amount' => 'decimal:2',
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
        return $this->hasMany(FatturaTraPrivatiPayment::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('fattura_tra_privati_calculation');
    }
}
