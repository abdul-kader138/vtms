@php
    $livewire ??= null;
@endphp

{{--
    Shared sign-in (/login) in the customer side's Metro look: the green
    line panel on the left, Filament's own form (2FA, device lock, Google)
    on the right. The panel's primary colour is switched to the line green
    on this page only.
--}}
<x-filament-panels::layout.base :livewire="$livewire">
    @vite('resources/css/customer.css')
    <style>
        .fi-metro-auth {
            --primary-50: 229, 242, 233; --primary-100: 204, 230, 213; --primary-200: 160, 210, 178;
            --primary-300: 103, 182, 133; --primary-400: 40, 150, 85; --primary-500: 0, 135, 62;
            --primary-600: 0, 122, 55; --primary-700: 0, 101, 45; --primary-800: 0, 82, 37; --primary-900: 0, 64, 29; --primary-950: 0, 40, 18;
            font-family: var(--font);
        }
        .fi-metro-auth .c-auth { min-height: 100vh; }
        .fi-metro-auth .c-auth-main { background: #fff; }
        .dark .fi-metro-auth .c-auth-main { background: rgb(var(--gray-950)); }
        .fi-metro-auth .fi-simple-header { align-items: flex-start; text-align: left; }
        .fi-metro-auth .fi-simple-header .fi-logo { display: none; }
        .fi-metro-auth .fi-simple-header-heading { font-size: 28px; font-weight: 750; letter-spacing: -0.02em; }
        .fi-metro-auth .fi-btn { min-height: 44px; }
        .fi-metro-auth .fi-input-wrp { min-height: 46px; }
        .fi-metro-auth .c-auth-customer { font-size: 14px; color: var(--ink-3); text-align: center; }
    </style>

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

            <main class="c-auth-main fi-simple-main-ctn">
                <div class="c-auth-panel fi-simple-main">
                    {{ $slot }}
                    <p class="c-auth-customer">
                        {{ __('New customer?') }} <a href="{{ route('customer.register') }}">{{ __('Create your account') }}</a>
                    </p>
                </div>
            </main>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $livewire?->getRenderHookScopes()) }}
    </div>
</x-filament-panels::layout.base>
