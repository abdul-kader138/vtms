<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->markLinkedLoginAsCustomer();
    }

    /** A login linked to a customer, with no staff role chosen, is a portal account. */
    protected function markLinkedLoginAsCustomer(): void
    {
        $user = $this->record->fresh();

        if ($user->customer_id !== null && $user->roles()->doesntExist()) {
            $user->markAsCustomer();
        }
    }
}
