<?php

namespace App\Models;

use App\Enums\TaxAdjustmentRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A review-modal submission (CustomerTaxDashboard::recalculateAction())
 * that TaxCalculationOrchestrator::commit() blocked because the submitting
 * user lacks finalize_tax_calculation, queued here instead of being
 * rejected outright. TaxAdjustmentRequestResource lets someone who does
 * have the permission approve it (re-running commit() with the stored
 * payload, under their own identity) or reject it.
 */
class TaxAdjustmentRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'customer_id', 'tax_year', 'payload', 'status',
        'requested_by', 'requested_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => TaxAdjustmentRequestStatus::class,
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tax_adjustment_request');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
