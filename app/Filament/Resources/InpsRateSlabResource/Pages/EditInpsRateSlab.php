<?php

namespace App\Filament\Resources\InpsRateSlabResource\Pages;

use App\Filament\Resources\InpsRateSlabResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInpsRateSlab extends EditRecord
{
    protected static string $resource = InpsRateSlabResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
