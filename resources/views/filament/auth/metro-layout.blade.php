@php
    $livewire ??= null;
@endphp

{{--
    Shared sign-in (/login) in the customer side's Metro look: the green
    line panel on the left, Filament's own form (2FA, device lock, Google)
    on the right, restyled by components/auth-brand-panel.blade.php.
--}}
<x-filament-panels::layout.base :livewire="$livewire">
    @vite('resources/css/customer.css')
    <style>
        .fi-metro-auth { font-family: var(--font); }
        .fi-metro-auth .c-auth-customer { font-size: 14px; color: var(--ink-3); text-align: center; }
        /* customer.css scopes base type to .c-body; the panel body is .fi-body. */
        .fi-metro-auth h1, .fi-metro-auth h2 { line-height: 1.15; text-wrap: balance; letter-spacing: -0.02em; margin: 0; }
        .fi-metro-auth .c-auth-side h1 { letter-spacing: -0.03em; }
        .fi-metro-auth ::selection { background: var(--line-tint-2); color: var(--ink); }
        .fi-metro-auth .c-auth-staff { font-size: 14px; color: var(--ink-3); text-align: center; margin-top: -12px; }
        .fi-metro-auth .c-auth-customer a { color: var(--line-strong); font-weight: 600; text-underline-offset: .2em; }
    </style>
    {{-- The shared sign-in is a light page whatever the panel's theme. --}}
    <script>document.documentElement.classList.remove('dark');</script>

    <div class="fi-metro-auth fi-simple-layout">
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
                <div class="c-auth-panel">
                    {{ $slot }}
                    <p class="c-auth-customer">
                        {{ __('New customer?') }} <a href="{{ route('customer.register') }}">{{ __('Create your account') }}</a>
                    </p>
                    <p class="c-auth-staff">{{ __('Office staff: sign in with your work email.') }}</p>
                </div>
            </main>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $livewire?->getRenderHookScopes()) }}
    </div>
</x-filament-panels::layout.base>
