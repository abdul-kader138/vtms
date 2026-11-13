<?php

namespace App\Filament\Resources\InpsRateSlabResource\Pages;

use App\Filament\Resources\InpsRateSlabResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInpsRateSlabs extends ListRecords
{
    protected static string $resource = InpsRateSlabResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
