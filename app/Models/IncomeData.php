<?php

namespace App\Models;

use App\Enums\IncomeSource;
use App\Support\Tax\InvoiceDocumentType;
use App\Support\Tax\TaxRules;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeData extends Model
{
    protected $table = 'income_data';

    protected $fillable = [
        'customer_id', 'source', 'transaction_date', 'collected_on', 'sales_amount',
        'taxable_sales', 'sale_taxes', 'reference_no', 'external_id',
        'meta', 'imported_by', 'tax_year', 'document_type', 'fingerprint',
        'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => IncomeSource::class,
            'transaction_date' => 'date',
            'collected_on' => 'date',
            'sales_amount' => 'decimal:2',
            'taxable_sales' => 'decimal:2',
            'sale_taxes' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // One "saving" hook: Eloquent fires saving before creating.
        static::saving(function (IncomeData $row) {
            // New row without collected_on at all → assume collected on the
            // document date (a cash-register sale always is). Given as null →
            // not collected yet.
            if (! $row->exists && ! array_key_exists('collected_on', $row->getAttributes())) {
                $row->collected_on = $row->transaction_date;
            }

            $row->income_year = $row->collected_on ? Carbon::parse($row->collected_on)->year : null;
        });
    }

    /**
     * Rows that make up a year's revenue: by collection year under the law
     * rules (cash basis), by document year under the legacy VTMS rules.
     */
    public function scopeRevenueForYear(Builder $query, int $year): Builder
    {
        return $query->where(TaxRules::isLaw() ? 'income_year' : 'tax_year', $year);
    }

    /** The date that places a row in a month/year for revenue purposes. */
    public static function revenueDateColumn(): string
    {
        return TaxRules::isLaw() ? 'collected_on' : 'transaction_date';
    }

    public function isCollected(): bool
    {
        return $this->collected_on !== null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(IncomeImportBatch::class, 'import_batch_id');
    }

    public function isCreditNote(): bool
    {
        return InvoiceDocumentType::isCreditNote($this->document_type);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
