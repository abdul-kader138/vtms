import { useEffect, useState } from 'react';
import TurnstileWidget from './TurnstileWidget';
import { postJson } from './api';
import { t } from './i18n';

const GOOGLE_ERROR_MESSAGES = {
    expired: () => t('That Google sign-in link expired. Please try again.'),
    failed: () => t('Something went wrong signing in with Google. Please try again.'),
};

export default function LoginForm({ siteKey }) {
    const [form, setForm] = useState({ email: '', password: '', remember: false });
    const [turnstileToken, setTurnstileToken] = useState('');
    const [turnstileKey, setTurnstileKey] = useState(0);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    // Surfaces the ?error= reason CustomerGoogleAuthController redirects
    // back with after a failed Google sign-in — otherwise the user lands
    // on a blank form with no indication anything happened.
    useEffect(() => {
        const reason = new URLSearchParams(window.location.search).get('error');

        if (reason) {
            setStatus({ type: 'error', message: GOOGLE_ERROR_MESSAGES[reason]?.() || t('Google sign-in failed. Please try again.') });
        }
    }, []);

    function update(field, value) {
        setForm((prev) => ({ ...prev, [field]: value }));
    }

    async function handleSubmit(event) {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setStatus(null);

        try {
            const data = await postJson('/api/v1/auth/login', {
                ...form,
                cf_turnstile_response: turnstileToken,
            });

            if (data.two_factor_required) {
                setStatus({ type: 'info', message: t('Enter the 6-digit code from your authenticator app to continue.') });
                // A real build would route to a challenge screen here and
                // post data.challenge_token to /api/v1/auth/login/challenge.
            } else {
                setStatus({ type: 'success', message: t('Welcome back, :name.', { name: data.first_name ?? data.data?.first_name ?? '' }) });
            }
        } catch (error) {
            setErrors(error.errors || {});
            setStatus({ type: 'error', message: error.message });
            // Tokens are single-use, so a rejected submit needs a fresh challenge.
            setTurnstileToken('');
            setTurnstileKey((key) => key + 1);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <form onSubmit={handleSubmit} className="auth-form">
            <label className="auth-field">
                <span>{t('Email')}</span>
                <input
                    type="email"
                    required
                    value={form.email}
                    onChange={(e) => update('email', e.target.value)}
                    autoComplete="email"
                />
                {errors.email && <small className="auth-error">{errors.email[0]}</small>}
            </label>

            <label className="auth-field">
                <span>{t('Password')}</span>
                <input
                    type="password"
                    required
                    value={form.password}
                    onChange={(e) => update('password', e.target.value)}
                    autoComplete="current-password"
                />
                {errors.password && <small className="auth-error">{errors.password[0]}</small>}
            </label>

            <label className="auth-checkbox">
                <input
                    type="checkbox"
                    checked={form.remember}
                    onChange={(e) => update('remember', e.target.checked)}
                />
                <span>{t('Remember me')}</span>
            </label>

            <TurnstileWidget key={turnstileKey} siteKey={siteKey} onVerify={setTurnstileToken} onExpire={() => setTurnstileToken('')} />
            {errors.cf_turnstile_response && <small className="auth-error">{errors.cf_turnstile_response[0]}</small>}

            {status && <p className={`auth-status auth-status--${status.type}`}>{status.message}</p>}

            <button type="submit" disabled={submitting}>
                {submitting ? t('Signing in…') : t('Sign in')}
            </button>
        </form>
    );
}
