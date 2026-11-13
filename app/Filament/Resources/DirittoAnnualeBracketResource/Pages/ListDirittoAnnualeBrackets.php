<?php

namespace App\Filament\Resources\DirittoAnnualeBracketResource\Pages;

use App\Filament\Resources\DirittoAnnualeBracketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDirittoAnnualeBrackets extends ListRecords
{
    protected static string $resource = DirittoAnnualeBracketResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
