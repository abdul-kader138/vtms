<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's own "this month is final" marker — see IncomeClosingService. */
class IncomeClosing extends Model
{
    protected $fillable = ['customer_id', 'year', 'month', 'closed_at'];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
