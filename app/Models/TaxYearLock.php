<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Locking a (customer, tax_year) pair blocks any further
 * TaxCalculationOrchestrator::commit() for it — see that class — until the
 * lock is removed. One row per locked year; presence of a row means locked.
 */
class TaxYearLock extends Model
{
    use LogsActivity;

    protected $fillable = [
        'customer_id', 'tax_year', 'locked_by', 'locked_at', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tax_year_lock');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
