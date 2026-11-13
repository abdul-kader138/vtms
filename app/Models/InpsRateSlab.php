<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class InpsRateSlab extends Model
{
    use LogsActivity;

    protected $fillable = [
        'slab_year', 'customer_type', 'income_from', 'income_to',
        'inps_rate', 'fixed_amount', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'income_from' => 'decimal:2',
            'income_to' => 'decimal:2',
            'inps_rate' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('inps_rate_slab');
    }
}
