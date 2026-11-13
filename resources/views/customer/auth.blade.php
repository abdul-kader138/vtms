<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \App\Models\Setting::get('app_name', 'VTMS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/customer-auth.jsx'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body.auth-body { margin: 0; background: #f8fafc; color: #0f172a; font-family: 'Inter', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; }
        .auth-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; box-sizing: border-box; }
        #customer-auth-root { width: 100%; max-width: 460px; }
        .auth-card { box-sizing: border-box; width: 100%; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 36px; }
        .auth-tabs { display: flex; gap: 8px; margin-bottom: 24px; }
        .auth-tabs button { flex: 1; height: 38px; border-radius: 5px; border: 1px solid #cbd5e1; background: #fff; color: #1e293b; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
        .auth-tabs button.active { background: #0e7490; color: #fff; border-color: #0e7490; }
        .auth-form { display: flex; flex-direction: column; gap: 16px; }
        .auth-field-row { display: flex; gap: 12px; }
        .auth-field, .auth-checkbox { display: flex; flex-direction: column; gap: 6px; min-width: 0; font-size: 13.5px; font-weight: 600; color: #0f172a; flex: 1; }
        .auth-checkbox { flex-direction: row; align-items: center; color: #475569; font-weight: 500; }
        .auth-phone-row { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); gap: 8px; }
        .auth-field input, .auth-phone-row select { box-sizing: border-box; width: 100%; min-width: 0; height: 44px; padding: 0 14px; border-radius: 5px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; font: inherit; font-size: 14px; font-weight: 400; outline: none; transition: border-color .2s ease, box-shadow .2s ease; }
        .auth-field input:focus, .auth-phone-row select:focus { border-color: #0e7490; box-shadow: 0 0 0 3px #ecfeff; }
        .auth-checkbox input[type="checkbox"] { width: 15px; height: 15px; accent-color: #0e7490; }
        .auth-error { color: #b91c1c; font-size: 13px; font-weight: 400; }
        .auth-status { margin: 0; font-size: 14px; padding: 13px 18px; border-radius: 5px; border: 1px solid; }
        .auth-status--success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .auth-status--error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .auth-status--info { background: #ecfeff; border-color: #a5f3fc; color: #155e75; }
        .auth-card button[type="submit"] { height: 38px; border-radius: 5px; border: 1px solid #0e7490; background: #0e7490; color: #fff; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
        .auth-card button[type="submit"]:hover { background: #155e75; border-color: #155e75; }
        .auth-card button[type="submit"]:disabled { opacity: .6; cursor: not-allowed; }
        .auth-card :is(a, button, input, select):focus-visible { outline: 2px solid #0e7490; outline-offset: 2px; }
        @media (max-width: 520px) {
            .auth-card { padding: 24px 18px; }
            .auth-field-row { flex-direction: column; }
        }
    </style>
</head>
<body class="auth-body">
    <main class="auth-page">
        <div
            id="customer-auth-root"
            data-mode="{{ $mode }}"
            data-turnstile-site-key="{{ config('services.turnstile.site_key') }}"
            data-phone-countries="{{ json_encode(\App\Support\PhoneNumber::countries()) }}"
            data-default-phone-country="{{ \App\Support\PhoneNumber::defaultRegion() }}"
            data-translations="{{ json_encode(app()->getLocale() === 'en' ? new \stdClass : json_decode(file_get_contents(lang_path(app()->getLocale().'.json')), true)) }}"
        ></div>
    </main>
</body>
</html>
