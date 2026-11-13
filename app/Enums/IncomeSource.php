<?php

namespace App\Enums;

enum IncomeSource: string
{
    case SdiFattura = 'sdi_fattura';
    case PrivatiDevice = 'privati_device';
}
