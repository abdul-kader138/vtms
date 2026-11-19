<?php

namespace App\Enums;

enum TaxAdjustmentRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
