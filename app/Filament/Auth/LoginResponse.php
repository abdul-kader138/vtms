<?php

namespace App\Filament\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Staff land on the admin dashboard. A customer who signs in on the panel
 * login (same `users` table/guard as the customer API) has no business in
 * the panel, so they go to their own portal instead.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();

        if ($user && ! $user->isStaff()) {
            return redirect()->route('customer.tax');
        }

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }
}
