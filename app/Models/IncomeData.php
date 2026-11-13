<?php

namespace App\Models;

use App\Enums\IncomeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeData extends Model
{
    protected $table = 'income_data';

    protected $fillable = [
        'customer_id', 'source', 'transaction_date', 'sales_amount',
        'taxable_sales', 'sale_taxes', 'reference_no', 'external_id',
        'meta', 'imported_by', 'tax_year',
    ];

    protected function casts(): array
    {
        return [
            'source' => IncomeSource::class,
            'transaction_date' => 'date',
            'sales_amount' => 'decimal:2',
            'taxable_sales' => 'decimal:2',
            'sale_taxes' => 'decimal:2',
            'meta' => 'array',
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
}
