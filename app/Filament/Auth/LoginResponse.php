<?php

namespace App\Filament\Auth;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * The panel is the site's only login now (see App\Filament\Auth\Login).
 * Staff land on the admin dashboard; a non-staff account that still manages
 * to authenticate here (same `users` table/guard as the customer API) has
 * no business in the panel, so it's bounced to the customer sign-in screen
 * instead — there is no customer-facing dashboard in this app.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();

        if ($user && ! $user->isStaff()) {
            return redirect()->route('customer.login');
        }

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }
}
