@php($showResend = $showResendVerification ?? false)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('customer.partials.head', ['title' => __($title)])
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
            <div class="c-auth-panel">
                <div @class(['c-status-icon', 'c-status-icon--error' => $tone === 'error' && ! $showResend]) aria-hidden="true">
                    @if ($showResend)
                        <svg class="c-icon" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    @elseif ($tone === 'error')
                        <svg class="c-icon" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    @else
                        <svg class="c-icon" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    @endif
                </div>
                <div>
                    <h2>{{ __($title) }}</h2>
                    <p class="c-sub">{{ __($message) }}</p>
                </div>

                @if ($showResend)
                    <div class="c-notice">
                        <svg class="c-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        <span>{{ __('After you confirm your email, your accountant links the account to your tax records. You will then see your payments here.') }}</span>
                    </div>
                    @if (session('status'))
                        <p class="c-notice c-notice--success" role="status">{{ session('status') }}</p>
                    @endif
                    <form class="c-form" method="POST" action="{{ route('customer.verification-resend') }}">
                        @csrf
                        <div class="c-field">
                            <label for="verification-email">{{ __('Registered email address') }}</label>
                            <input id="verification-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" required @error('email') aria-invalid="true" aria-describedby="verification-error" @enderror>
                            @error('email')
                                <p class="c-error" id="verification-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="c-btn c-btn--quiet c-btn--block">{{ __('Resend verification email') }}</button>
                    </form>
                @endif

                <a class="c-btn c-btn--block" href="{{ $ctaHref }}">{{ __($ctaLabel) }}</a>
            </div>
        </main>
    </div>
</body>
</html>
