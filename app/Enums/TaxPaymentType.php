<?php

namespace App\Enums;

enum TaxPaymentType: string
{
    case Balance = 'balance';
    case FirstAdvance = 'first_advance';
    case SecondAdvance = 'second_advance';
}
