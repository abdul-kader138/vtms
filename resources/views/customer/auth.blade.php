@php
    $translationFile = lang_path(app()->getLocale().'.json');
    $translations = app()->getLocale() !== 'en' && is_file($translationFile)
        ? json_decode(file_get_contents($translationFile), true)
        : new \stdClass;
    $title = match ($mode) {
        'register' => __('Create account'),
        'reset-password' => __('Choose a new password'),
        default => __('Sign in'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('customer.partials.head', ['title' => $title, 'scripts' => ['resources/js/customer-auth.jsx']])
</head>
<body class="c-body">
    <div class="c-auth">
        <aside class="c-auth-side">
            @include('customer.partials.mark')
            <h1>{{ __('Your taxes, one stop at a time.') }}</h1>
            <p>{{ __('Your accountant keeps your figures up to date. Here you see what to pay, when, and how.') }}</p>
            <ol class="c-route" aria-label="{{ __('What you can do here') }}">
                <li><span>{{ __('Next payment') }}<small>{{ __('Amount, deadline and F24 code') }}</small></span></li>
                <li><span>{{ __('Your year') }}<small>{{ __('Revenue against the €85,000 limit') }}</small></span></li>
                <li><span>{{ __('Documents') }}<small>{{ __('Income statement and receipts') }}</small></span></li>
            </ol>
        </aside>

        <main class="c-auth-main">
            <div
                id="customer-auth-root"
                class="c-auth-panel"
                data-mode="{{ $mode }}"
                data-turnstile-site-key="{{ config('services.turnstile.site_key') }}"
                data-google="{{ filled(config('services.google.client_id')) && filled(config('services.google.client_secret')) ? route('customer.auth.google.redirect') : '' }}"
                data-phone-countries="{{ json_encode(\App\Support\PhoneNumber::countries()) }}"
                data-default-phone-country="{{ \App\Support\PhoneNumber::defaultRegion() }}"
                data-translations="{{ json_encode($translations) }}"
            ></div>
        </main>
    </div>
</body>
</html>
