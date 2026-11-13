<?php

namespace App\Filament\Resources\InpsContributionRuleResource\Pages;

use App\Filament\Resources\InpsContributionRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInpsContributionRules extends ManageRecords
{
    protected static string $resource = InpsContributionRuleResource::class;

    public function getSubheading(): ?string
    {
        return __('Used by the "Italian law" rules for Artigiani and Commercianti. Update them each year from the INPS circular; rows marked in red were copied from the previous year.');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
