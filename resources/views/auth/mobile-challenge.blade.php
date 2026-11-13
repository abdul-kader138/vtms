<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Security check') }}</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body style="background:#0f172a;color:white;font:18px sans-serif;padding:24px">
    <h2>{{ __('Security check') }}</h2>
    <p>{{ __('Complete the check to continue signing in.') }}</p>
    @if (filled(config('services.turnstile.site_key')))
        <div class="cf-turnstile"
             data-sitekey="{{ config('services.turnstile.site_key') }}"
             data-callback="complete"
             data-error-callback="failed"
             data-expired-callback="expired"></div>
        <p id="status" style="color:#fca5a5"></p>
        <script>
            // Posted back to the native app's WebView message handler —
            // { token } on success, { error } otherwise, so the app can
            // show a real message or offer a retry instead of hanging on
            // a widget that silently errored or expired mid-challenge.
            function post(payload) {
                if (window.ReactNativeWebView) {
                    window.ReactNativeWebView.postMessage(JSON.stringify(payload));
                }
            }

            function complete(token) {
                document.getElementById('status').textContent = '';
                post({ token });
            }

            function failed() {
                document.getElementById('status').textContent = 'The check failed to load. Please try again.';
                post({ error: 'failed' });
            }

            function expired() {
                document.getElementById('status').textContent = 'The check expired — please complete it again.';
                post({ error: 'expired' });
            }
        </script>
    @else
        {{-- Native clients already check GET /api/v1/auth/meta before ever
             opening this page, so landing here with no site key configured
             means the backend's captcha was disabled after the app build
             shipped — nothing to solve, so say so instead of showing a
             widget that would never render. --}}
        <p>{{ __('No security check is required right now. You can close this screen.') }}</p>
        <script>
            if (window.ReactNativeWebView) {
                window.ReactNativeWebView.postMessage(JSON.stringify({ token: null }));
            }
        </script>
    @endif
</body>
</html>
