<?php

namespace App\Filament\Auth\Concerns;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\ViewField;

/**
 * Used by App\Filament\Auth\Login — a hidden field populated client-side
 * (resources/views/filament/forms/components/device-fingerprint.blade.php)
 * so App\Services\DeviceLockService has something to compare a user's
 * login against. Same wiring pattern as HasTurnstileField.
 */
trait HasDeviceFingerprintField
{
    protected function getDeviceFingerprintFormComponent(): Component
    {
        return ViewField::make('device_fingerprint')
            ->view('filament.forms.components.device-fingerprint')
            ->label(false)
            ->dehydrated(true);
    }
}
