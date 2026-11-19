<?php

namespace App\Models;

use App\Enums\TaxAdjustmentRequestStatus;
use App\Filament\Resources\TaxAdjustmentRequestResource;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

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

    /**
     * A pending request otherwise just sits in the queue until someone
     * happens to open Tax Adjustment Requests — notify everyone who can
     * actually act on it (database/bell notification, matching the panel's
     * existing databaseNotifications() setup) the moment it's created, and
     * notify the original requester back once it's been approved or
     * rejected.
     */
    protected static function booted(): void
    {
        static::created(function (self $request) {
            if ($request->status === TaxAdjustmentRequestStatus::Pending) {
                $request->notifyApprovers();
            }
        });

        static::updated(function (self $request) {
            if ($request->isDirty('status') && in_array($request->status, [TaxAdjustmentRequestStatus::Approved, TaxAdjustmentRequestStatus::Rejected], true)) {
                $request->notifyRequester();
            }
        });
    }

    private function notifyApprovers(): void
    {
        try {
            $approvers = User::permission('finalize_tax_calculation')->get();
        } catch (PermissionDoesNotExist) {
            // No such permission yet means nobody can approve — the request
            // still gets queued rather than failing the whole submission.
            return;
        }

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('New tax adjustment request'))
            ->body(__(':customer, :year — submitted by :by.', [
                'customer' => $this->customer?->name ?? __('Unknown customer'),
                'year' => $this->tax_year,
                'by' => $this->requestedBy?->name ?? __('a staff member'),
            ]))
            ->icon('heroicon-o-inbox-stack')
            ->actions([
                NotificationAction::make('review')->label(__('Review'))->url(TaxAdjustmentRequestResource::getUrl())->markAsRead(),
            ])
            ->sendToDatabase($approvers);
    }

    private function notifyRequester(): void
    {
        if ($this->requestedBy === null) {
            return;
        }

        $approved = $this->status === TaxAdjustmentRequestStatus::Approved;

        $notification = Notification::make()
            ->title($approved ? __('Your tax adjustment request was approved') : __('Your tax adjustment request was rejected'))
            ->body(__(':customer, :year', ['customer' => $this->customer?->name ?? __('Unknown customer'), 'year' => $this->tax_year])
                .($this->review_note ? ' — '.$this->review_note : ''))
            ->icon($approved ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle');

        $approved ? $notification->success() : $notification->danger();

        $notification->sendToDatabase($this->requestedBy);
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
