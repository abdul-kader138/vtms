<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Digital services bought from a foreign supplier (Facebook/Google/Canva
 * ads and the like): under Regime Forfettario these trigger reverse-charge
 * IVA that the customer self-assesses and cannot deduct — see
 * App\Services\Tax\ForeignServiceIvaService.
 */
class ForeignServicePurchase extends Model
{
    protected $fillable = ['customer_id', 'purchase_date', 'provider', 'description', 'amount', 'tax_year'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ForeignServicePurchase $purchase) {
            if ($purchase->purchase_date !== null) {
                $purchase->tax_year = Carbon::parse($purchase->purchase_date)->year;
            }
        });
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('tax_year', $year);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
