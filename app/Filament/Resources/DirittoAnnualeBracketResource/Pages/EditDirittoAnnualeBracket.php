<?php

namespace App\Filament\Resources\DirittoAnnualeBracketResource\Pages;

use App\Filament\Resources\DirittoAnnualeBracketResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDirittoAnnualeBracket extends EditRecord
{
    protected static string $resource = DirittoAnnualeBracketResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
