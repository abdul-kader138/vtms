<?php

namespace App\Listeners;

use App\Models\UserSession;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Records sign-in activity to the audit log (activity_log table). Registered
 * as an event subscriber in AppServiceProvider::boot() rather than relying on
 * event auto-discovery, since this app has no EventServiceProvider.
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'guard' => $event->guard,
                'ip' => request()->ip(),
            ])
            ->log('User logged in');
    }

    public function handleLogout(Logout $event): void
    {
        // Release only the session that actually logged out. When an older
        // device is rejected by EnsureSingleDevice, its token differs from
        // the active record and must not delete the newer device's session.
        $deviceToken = request()->hasSession()
            ? request()->session()->get('device_session_token')
            : null;

        if ($event->user && filled($deviceToken)) {
            UserSession::where('user_id', $event->user->getAuthIdentifier())
                ->where('session_token', $deviceToken)
                ->delete();
        }

        if (! $event->user) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'guard' => $event->guard,
                'ip' => request()->ip(),
            ])
            ->log('User logged out');
    }

    public function handleFailedLogin(Failed $event): void
    {
        activity('auth')
            ->withProperties([
                'guard' => $event->guard,
                'email' => $event->credentials['email'] ?? null,
                'ip' => request()->ip(),
            ])
            ->log('Failed login attempt');
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailedLogin',
        ];
    }
}
