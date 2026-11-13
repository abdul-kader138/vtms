<?php

namespace App\Enums;

enum InpsCalculationMethod: string
{
    case Minimum = 'minimum';
    case Percentage = 'percentage';
    // Artigiani/Commercianti under the law rules: fixed contributions on
    // the minimale + a percentage on income above it.
    case FixedPlusPercentage = 'fixed_plus_percentage';
}
