<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureSingleDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) return $next($request);
        $token = $request->session()->get('device_session_token');
        $record = UserSession::where('user_id', $request->user()->id)->first();
        $deviceName = Str::limit((string) $request->userAgent(), 80);
        // A missing token means this is a newly authenticated session. In
        // that case, replacing the previous token intentionally logs out
        // the old device on its next request.
        if (! $token) {
            $cooldown = max(0, (int) \App\Models\Setting::get('single_device_switch_cooldown_minutes', 10));
            $sameDevice = $record
                && hash_equals((string) $record->device_name, $deviceName)
                && (string) $record->ip_address === (string) $request->ip();

            if ($record && ! $sameDevice && $cooldown > 0 && $record->last_seen_at?->gt(now()->subMinutes($cooldown))) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()
                    ->route('filament.admin.auth.login')
                    ->with('error', __('This account was recently used on another device. Please try again in :minutes minutes.', ['minutes' => $cooldown]));
            }

            $token = Str::random(64);
            UserSession::updateOrCreate(['user_id' => $request->user()->id], ['session_token' => $token, 'device_name' => $deviceName, 'ip_address' => $request->ip(), 'last_seen_at' => now()]);
            $request->session()->put('device_session_token', $token);
            return $next($request);
        }

        // Never rotate the token for a session that already has one. Doing
        // so would allow two devices to alternate requests and both remain
        // usable. The older device is explicitly logged out instead.
        if (! $record || ! hash_equals($record->session_token, $token)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('Your account was signed in on another device.'),
                    'code' => 'single_device_session_replaced',
                ], 401);
            }

            return redirect()
                ->route('filament.admin.auth.login')
                ->with('error', __('Your account was signed in on another device.'));
        }

        // Throttled write: a logged-in user hits this middleware on every
        // request, but "last seen" doesn't need second-level precision —
        // skip the UPDATE unless the stored value is stale by more than a
        // minute, to avoid a write query on every single page load.
        if (! $record->last_seen_at || $record->last_seen_at->lt(now()->subMinute())) {
            $record->update(['last_seen_at' => now()]);
        }

        return $next($request);
    }
}
