{{--
    Panel sign-in pages (login, password reset, email verification) in the
    customer side's Metro look — see resources/css/customer.css and
    resources/views/filament/auth/metro-layout.blade.php, which supplies the
    green side panel and the brand mark. This only restyles Filament's form.
--}}
<style>
    body.fi-body { background: #fff !important; color: var(--ink) !important; color-scheme: light !important; font-family: var(--font) !important; -webkit-font-smoothing: antialiased; }

    .fi-metro-auth .fi-simple-page { width: 100%; }
    .fi-metro-auth .fi-simple-page > section { display: flex; flex-direction: column; gap: 24px; }
    .fi-metro-auth .fi-simple-header { align-items: flex-start !important; text-align: left !important; }
    .fi-metro-auth .fi-simple-header .fi-logo { display: none !important; }
    .fi-metro-auth .fi-simple-header-heading { text-align: left !important; color: var(--ink) !important; font-size: 28px !important; font-weight: 750 !important; letter-spacing: -0.02em !important; }
    .fi-metro-auth .fi-simple-header-subheading { text-align: left !important; color: var(--ink-2) !important; font-size: 15px !important; }

    .fi-metro-auth .fi-fo-field-wrp-label span { color: var(--ink) !important; font-size: 14px !important; font-weight: 650 !important; }
    .fi-metro-auth .fi-fo-field-wrp-label sup { color: var(--ink-3) !important; }
    .fi-metro-auth .fi-form-actions { margin-top: 0 !important; }
    .fi-metro-auth .fi-form, .fi-metro-auth .fi-fo-component-ctn { row-gap: 18px !important; }
    /* Hidden fields (device fingerprint; Turnstile when unconfigured) are
       zero-height grid items that would add an extra row gap. */
    .fi-metro-auth .fi-fo-component-ctn > :not(:has(label, button, iframe, textarea, select, input:not([type="hidden"]), [class*="turnstile"])) { display: none !important; }
    .fi-metro-auth .fi-fo-field-wrp-error-message { color: var(--overdue-ink) !important; font-size: 14px !important; }
    .fi-metro-auth .fi-fo-field-wrp-helper-text { color: var(--ink-3) !important; font-size: 14px !important; }

    /* The line green replaces the panel's primary colour on these pages. */
    .fi-metro-auth {
        --primary-50: 229, 242, 233; --primary-100: 204, 230, 213; --primary-200: 160, 210, 178;
        --primary-300: 103, 182, 133; --primary-400: 40, 150, 85; --primary-500: 0, 135, 62;
        --primary-600: 0, 122, 55; --primary-700: 0, 101, 45; --primary-800: 0, 82, 37; --primary-900: 0, 64, 29; --primary-950: 0, 40, 18;
    }
    .fi-metro-auth .fi-link { --c-400: 0, 150, 85; --c-500: 0, 135, 62; --c-600: 0, 101, 45; }
    .fi-metro-auth .fi-input-wrp { min-height: 46px; border: 1px solid var(--rule-strong) !important; border-radius: var(--radius) !important; background: #fff !important; box-shadow: none !important; }
    .fi-metro-auth .fi-input-wrp:hover { border-color: var(--ink-3) !important; }
    .fi-metro-auth .fi-input-wrp:focus-within { border-color: var(--line) !important; box-shadow: 0 0 0 3px var(--line-tint-2) !important; }
    .fi-metro-auth .fi-input { font-size: 16px !important; color: var(--ink) !important; height: 44px; }
    .fi-metro-auth input:-webkit-autofill { -webkit-text-fill-color: var(--ink) !important; -webkit-box-shadow: 0 0 0 1000px #fff inset !important; }

    .fi-metro-auth .fi-btn-color-primary, .fi-metro-auth button[type="submit"] {
        min-height: 44px; width: 100%; border-radius: var(--radius) !important; background: var(--line) !important; color: #fff !important;
        font-size: 15px !important; font-weight: 650 !important; box-shadow: none !important;
    }
    .fi-metro-auth .fi-btn-color-primary:hover, .fi-metro-auth button[type="submit"]:hover { background: var(--line-strong) !important; }
    .fi-metro-auth .fi-btn-color-primary * { color: #fff !important; }

    .fi-metro-auth .fi-link, .fi-metro-auth .fi-link *, .fi-metro-auth .fi-simple-page a { color: var(--line-strong) !important; font-weight: 600 !important; }
    .fi-metro-auth .fi-link:hover { text-decoration: underline !important; text-underline-offset: .2em; }
    .fi-metro-auth .fi-checkbox-input { border: 1px solid var(--rule-strong) !important; border-radius: 4px !important; width: 18px; height: 18px; box-shadow: none !important; }
    .fi-metro-auth .fi-checkbox-input:checked { background-color: var(--line) !important; border-color: var(--line) !important; }
    .fi-metro-auth :is(a, button, input):focus-visible { outline: 2px solid var(--line) !important; outline-offset: 2px !important; }

    .auth-flash { padding: 12px 14px; border-radius: var(--radius); background: var(--line-tint); color: var(--line-strong); font-size: 15px; }
</style>

@if (session('success'))
    <div class="auth-flash" role="status">{{ session('success') }}</div>
@endif
