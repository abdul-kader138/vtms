<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DirittoAnnualeBracket extends Model
{
    use LogsActivity;

    protected $fillable = [
        'bracket_year', 'revenue_from', 'revenue_to', 'amount', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'revenue_from' => 'decimal:2',
            'revenue_to' => 'decimal:2',
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DirittoAnnualePayment::class, 'diritto_annuale_bracket_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('diritto_annuale_bracket');
    }
}
