@php
    $appName = \App\Models\Setting::get('app_name', 'VTMS');
    $tagline = \App\Models\Setting::get('app_tagline', '');
    $logoPath = \App\Models\Setting::get('app_logo');
    $logoUrl = $logoPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) : null;
    $iconPath = \App\Models\Setting::get('app_icon');
    $iconUrl = $iconPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($iconPath) : null;
@endphp

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Archivo:wght@600;700;800&display=swap" rel="stylesheet">


<style>
    body.fi-body {
        --auth-signal: #0e7490;
        --auth-signal-hover: #155e75;
        --auth-signal-tint: #ecfeff;
        --auth-border: #e2e8f0;
        --auth-input-border: #cbd5e1;
        --auth-text: #0f172a;
        --auth-text-secondary: #475569;
        --auth-text-muted: #64748b;
        --auth-font: 'Inter', system-ui, -apple-system, sans-serif;
        --auth-font-display: 'Archivo', 'Inter', sans-serif;

        background: #f8fafc !important;
        font-family: var(--auth-font) !important;
        color-scheme: light !important;
        color: var(--auth-text) !important;
        min-height: 100vh;
        margin: 0;
        -webkit-font-smoothing: antialiased;
    }

    .fi-simple-main {
        max-width: 460px !important;
        width: 100% !important;
        margin: 0 auto !important;
        padding: 0 !important;
        background: transparent !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        border: none !important;
    }

    .fi-simple-main.ring-1 {
        --tw-ring-shadow: none !important;
        box-shadow: none !important;
    }

    .fi-simple-page {
        min-height: 100vh;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 3rem 1.25rem !important;
        box-sizing: border-box !important;
        background: transparent !important;
    }

    .fi-simple-page > section,
    .fi-simple-main > section {
        max-width: 460px !important;
        width: 100% !important;
        background: #ffffff !important;
        border: 1px solid var(--auth-border) !important;
        border-radius: 8px !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05) !important;
        padding: 36px !important;
        box-sizing: border-box !important;
    }

    .fi-simple-page h1,
    .fi-simple-page h2,
    .fi-simple-page .text-gray-950 {
        color: var(--auth-text) !important;
        font-family: var(--auth-font-display) !important;
        font-size: 26px !important;
        font-weight: 800 !important;
        letter-spacing: -0.02em !important;
        text-align: center !important;
        margin-top: 0 !important;
    }

    .fi-simple-page p,
    .fi-simple-page .text-gray-500,
    .fi-simple-page .text-gray-600 {
        color: var(--auth-text-secondary) !important;
        font-size: 14px !important;
        text-align: center !important;
    }

    .fi-simple-page .fi-fo-field-wrp-error-message {
        color: #b91c1c !important;
        font-size: 13px !important;
        text-align: left !important;
    }

    .fi-simple-page .fi-fo-field-wrp-helper-text {
        font-size: 12px !important;
        color: var(--auth-text-muted) !important;
        text-align: left !important;
    }

    .fi-simple-page .fi-form-actions .fi-ac {
        grid-template-columns: minmax(0, 1fr) !important;
        justify-items: center !important;
    }

    .fi-simple-page label,
    .fi-simple-page .fi-fo-field-wrp-label span {
        color: var(--auth-text) !important;
        font-size: 13.5px !important;
        font-weight: 600 !important;
    }

    .fi-simple-page input[type="email"],
    .fi-simple-page input[type="password"],
    .fi-simple-page input[type="text"] {
        background: #ffffff !important;
        border: 0 !important;
        color: var(--auth-text) !important;
        border-radius: 5px !important;
        height: 44px !important;
        padding: 0 14px !important;
        font-size: 14px !important;
        text-align: left !important;
        box-shadow: none !important;
        outline: none !important;
    }

    .fi-simple-header .fi-logo {
        display: none !important;
    }

    .fi-simple-page .fi-input-wrp {
        border: 1px solid var(--auth-input-border) !important;
        box-shadow: none !important;
        background: #ffffff !important;
        border-radius: 5px !important;
        --tw-ring-shadow: none !important;
        --tw-ring-offset-shadow: none !important;
        transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
    }

    .fi-simple-page .fi-input-wrp:focus-within {
        border-color: var(--auth-signal) !important;
        box-shadow: 0 0 0 3px var(--auth-signal-tint) !important;
    }

    .fi-simple-page input:-webkit-autofill,
    .fi-simple-page input:-webkit-autofill:hover,
    .fi-simple-page input:-webkit-autofill:focus {
        -webkit-text-fill-color: var(--auth-text) !important;
        -webkit-box-shadow: 0 0 0 1000px #ffffff inset !important;
        transition: background-color 9999s ease-out 0s !important;
    }

    .fi-simple-page button[type="submit"],
    .fi-simple-page .fi-btn-primary {
        background: var(--auth-signal) !important;
        color: #ffffff !important;
        border: 1px solid var(--auth-signal) !important;
        border-radius: 5px !important;
        height: 38px !important;
        padding: 0 17px !important;
        font-size: 14px !important;
        font-weight: 600 !important;
        box-shadow: none !important;
        transition: background-color 0.15s ease, border-color 0.15s ease !important;
        cursor: pointer !important;
        width: 100% !important;
    }

    .fi-simple-page button[type="submit"] *,
    .fi-simple-page .fi-btn-primary * {
        color: #ffffff !important;
    }

    .fi-simple-page button[type="submit"]:hover,
    .fi-simple-page .fi-btn-primary:hover {
        background: var(--auth-signal-hover) !important;
        border-color: var(--auth-signal-hover) !important;
    }

    .fi-simple-page a,
    .fi-simple-page .fi-link,
    .fi-simple-page .text-primary-600,
    .fi-simple-page .text-custom-600 {
        color: var(--auth-signal) !important;
        font-size: 13.5px !important;
        font-weight: 600 !important;
        text-decoration: none !important;
    }

    .fi-simple-page a:hover,
    .fi-simple-page .fi-link:hover {
        color: var(--auth-signal-hover) !important;
        text-decoration: underline !important;
        text-underline-offset: 3px !important;
    }

    .fi-simple-page :is(a, button, input):focus-visible {
        outline: 2px solid var(--auth-signal) !important;
        outline-offset: 2px !important;
    }

    .fi-simple-page input[type="checkbox"],
    .fi-simple-page .fi-checkbox-input {
        accent-color: var(--auth-signal) !important;
        border: 1px solid var(--auth-input-border) !important;
        border-radius: 4px !important;
        width: 15px !important;
        height: 15px !important;
        cursor: pointer !important;
    }

    .fi-simple-page .fi-fo-checkbox label,
    .fi-simple-page .fi-checkbox-label,
    .fi-simple-page label:has(input[type="checkbox"]) {
        color: var(--auth-text-secondary) !important;
        font-weight: 500 !important;
        cursor: pointer !important;
    }

    .auth-brand { display: flex; flex-direction: column; align-items: center; margin-bottom: 24px; text-align: center; }
    .fi-simple-page a.auth-brand-link { display: inline-flex; align-items: center; gap: 11px; color: var(--auth-text) !important; text-decoration: none !important; }
    .auth-brand-icon { width: 36px; height: 36px; border-radius: 6px; background: #121212; color: #ffffff; display: grid; place-items: center; overflow: hidden; }
    .auth-brand-icon img { width: 100%; height: 100%; object-fit: contain; }
    .auth-brand-initial { font-family: var(--auth-font-display); font-size: 16px; font-weight: 800; }
    .auth-brand-name { font-family: var(--auth-font-display); font-size: 20px; font-weight: 800; letter-spacing: -0.02em; color: var(--auth-text); }
    .auth-brand-tagline { margin-top: 6px; font-size: 10px; font-weight: 700; letter-spacing: 0.09em; text-transform: uppercase; color: var(--auth-signal); }
    .auth-flash { width: 100%; max-width: 460px; margin: 0 auto 16px; padding: 13px 18px; box-sizing: border-box; border: 1px solid #a7f3d0; border-radius: 5px; background: #ecfdf5; color: #065f46; font-size: 14px; font-weight: 500; text-align: center; }

    @media (max-width: 520px) {
        .fi-simple-page { padding: 32px 16px 20px !important; justify-content: flex-start !important; }
        .fi-simple-page > section,
        .fi-simple-main > section { padding: 24px 18px !important; }
    }
</style>

<div class="auth-brand">
    <a class="auth-brand-link" href="{{ url('/') }}">
        <span class="auth-brand-icon">
            @if($iconUrl || $logoUrl)
                <img src="{{ $iconUrl ?: $logoUrl }}" alt="">
            @else
                <span class="auth-brand-initial">{{ Str::upper(Str::substr($appName, 0, 1)) }}</span>
            @endif
        </span>
        <span class="auth-brand-name">{{ $appName }}</span>
    </a>
    <span class="auth-brand-tagline">{{ $tagline }}</span>
</div>

@if(session('success'))
    <div class="auth-flash" role="status">{{ session('success') }}</div>
@endif
