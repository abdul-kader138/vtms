<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:'.implode(',', User::SUPPORTED_LOCALES)],
        ]);

        // Guests (the public catalog, sign-in screens) keep it for the session;
        // signed-in users keep it on their account so emails follow it too.
        $request->session()->put('locale', $validated['locale']);
        $request->user()?->forceFill(['locale' => $validated['locale']])->save();

        return back();
    }
}
