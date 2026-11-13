<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Model;

/** One year's INPS IVS parameters for Artigiani or Commercianti (see migration). */
class InpsContributionRule extends Model
{
    protected $fillable = [
        'year', 'customer_type', 'minimum_income', 'rate', 'maternity_fee',
        'threshold', 'rate_above_threshold', 'maximum_income', 'source',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'minimum_income' => 'decimal:2',
            'rate' => 'decimal:2',
            'maternity_fee' => 'decimal:2',
            'threshold' => 'decimal:2',
            'rate_above_threshold' => 'decimal:2',
            'maximum_income' => 'decimal:2',
        ];
    }

    public static function for(CustomerType $type, int $year): ?self
    {
        return static::where('customer_type', $type)->where('year', $year)->first();
    }

    /** Full-year fixed contributions (minimale × rate + maternity fee). */
    public function fixedAmount(): float
    {
        return round((float) $this->minimum_income * (float) $this->rate / 100 + (float) $this->maternity_fee, 2);
    }

    public function isVerified(): bool
    {
        return ! str_starts_with((string) $this->source, 'CARRIED FORWARD');
    }
}
