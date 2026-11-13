<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
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
