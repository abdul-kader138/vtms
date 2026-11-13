<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __($title) }} · {{ \App\Models\Setting::get('app_name', 'VTMS') }}</title>
    @vite(['resources/css/app.css'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body.status-body { margin: 0; background: #f8fafc; color: #0f172a; font-family: 'Inter', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; }
        .status-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; box-sizing: border-box; }
        .status-card { box-sizing: border-box; width: 100%; max-width: 460px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 36px; text-align: center; }
        .status-icon { width: 52px; height: 52px; margin: 0 auto 18px; border-radius: 50%; display: grid; place-items: center; }
        .status-icon.tone-success { background: #ecfdf5; color: #059669; }
        .status-icon.tone-error { background: #fef2f2; color: #e11d48; }
        .status-icon.tone-info { background: #ecfeff; color: #155e75; }
        .status-card h1 { font-size: 26px; letter-spacing: -0.02em; margin-bottom: 8px; }
        .status-message { color: #475569; font-size: 14px; margin-bottom: 24px; }
        .status-actions { display: grid; gap: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 38px; border-radius: 5px; font-size: 14px; font-weight: 600; text-decoration: none; box-sizing: border-box; cursor: pointer; }
        .btn-primary { background: #0e7490; color: #fff; border: 1px solid #0e7490; }
        .btn-primary:hover { background: #155e75; border-color: #155e75; }
        .btn-secondary { background: #fff; color: #1e293b; border: 1px solid #cbd5e1; }
        .resend-form { text-align: left; display: grid; gap: 12px; padding-bottom: 20px; margin-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
        .resend-form label { display: block; font-weight: 600; font-size: 13.5px; color: #0f172a; margin-bottom: 6px; }
        .resend-form input { width: 100%; box-sizing: border-box; padding: 12px 14px; border-radius: 5px; border: 1px solid #cbd5e1; font: inherit; font-size: 14px; }
        .resend-error { color: #b91c1c; font-size: 13px; margin-top: 6px; }
        .resend-status { text-align: left; padding: 12px 14px; margin-bottom: 16px; border-radius: 5px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; font-size: 13.5px; }
    </style>
</head>
<body class="status-body">
    <main class="status-page">
        @php($showResend = $showResendVerification ?? false)
        <div class="status-card">
            <div class="status-icon tone-{{ $showResend ? 'info' : $tone }}" aria-hidden="true">
                @if($showResend)
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                @elseif($tone === 'error')
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                @else
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                @endif
            </div>
            <h1>{{ __($title) }}</h1>
            <p class="status-message">{{ __($message) }}</p>

            <div class="status-actions">
                @if($showResend)
                    @if(session('status'))
                        <p class="resend-status" role="status">{{ session('status') }}</p>
                    @endif
                    <form class="resend-form" method="POST" action="{{ route('customer.verification-resend') }}">
                        @csrf
                        <div>
                            <label for="verification-email">{{ __('Registered email address') }}</label>
                            <input id="verification-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" required @error('email') aria-invalid="true" aria-describedby="verification-error" @enderror>
                            @error('email')
                                <p class="resend-error" id="verification-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Resend verification email') }}</button>
                    </form>
                @endif
                <a class="btn {{ $showResend ? 'btn-secondary' : 'btn-primary' }}" href="{{ $ctaHref }}">{{ __($ctaLabel) }}</a>
            </div>
        </div>
    </main>
</body>
</html>
