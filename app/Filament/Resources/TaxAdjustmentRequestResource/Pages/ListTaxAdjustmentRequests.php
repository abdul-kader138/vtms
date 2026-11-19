<?php

namespace App\Filament\Resources\TaxAdjustmentRequestResource\Pages;

use App\Filament\Resources\TaxAdjustmentRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListTaxAdjustmentRequests extends ListRecords
{
    protected static string $resource = TaxAdjustmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
