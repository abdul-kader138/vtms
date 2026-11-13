<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One imported file: its outcome, and the handle to undo it. */
class IncomeImportBatch extends Model
{
    public const CHANNEL_UPLOAD = 'upload';

    public const CHANNEL_INBOX = 'inbox';

    public const CHANNEL_CLI = 'cli';

    protected $fillable = [
        'customer_id', 'format', 'channel', 'original_filename', 'file_hash', 'stored_path',
        'rows_read', 'imported', 'duplicates', 'skipped', 'failed', 'credit_notes',
        'total_amount', 'period_from', 'period_to', 'warnings', 'errors',
        'recalculated_years', 'imported_by', 'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
            'warnings' => 'array',
            'errors' => 'array',
            'recalculated_years' => 'array',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(IncomeData::class, 'import_batch_id');
    }

    public function isRolledBack(): bool
    {
        return $this->rolled_back_at !== null;
    }
}
