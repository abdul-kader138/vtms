import { useEffect, useState } from 'react';
import TurnstileWidget from './TurnstileWidget';
import { postJson } from './api';
import { t } from './i18n';
import { Field, GoogleButton, Notice, PasswordField } from './ui';

const GOOGLE_ERROR_MESSAGES = {
    expired: () => t('That Google sign-in link expired. Please try again.'),
    failed: () => t('Something went wrong signing in with Google. Please try again.'),
};

export default function LoginForm({ siteKey, googleUrl, onForgot }) {
    const [form, setForm] = useState({ email: '', password: '', remember: false });
    const [turnstileToken, setTurnstileToken] = useState('');
    const [turnstileKey, setTurnstileKey] = useState(0);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    // Set when the account has two-factor authentication: the second step.
    const [challenge, setChallenge] = useState(null);
    const [code, setCode] = useState('');

    // Surfaces the ?error= reason CustomerGoogleAuthController redirects
    // back with after a failed Google sign-in.
    useEffect(() => {
        const reason = new URLSearchParams(window.location.search).get('error');

        if (reason) {
            setStatus({ type: 'error', message: GOOGLE_ERROR_MESSAGES[reason]?.() || t('Google sign-in failed. Please try again.') });
        }
    }, []);

    function update(field, value) {
        setForm((prev) => ({ ...prev, [field]: value }));
    }

    function failed(error) {
        setErrors(error.errors || {});
        setStatus({ type: 'error', message: error.message });
    }

    async function handleSubmit(event) {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setStatus(null);

        try {
            const data = await postJson('/api/v1/auth/login', { ...form, cf_turnstile_response: turnstileToken });

            if (data.two_factor_required) {
                setChallenge(data.challenge_token);
                setSubmitting(false);
                return;
            }

            window.location.assign('/');
        } catch (error) {
            failed(error);
            // Tokens are single-use, so a rejected submit needs a fresh challenge.
            setTurnstileToken('');
            setTurnstileKey((key) => key + 1);
            setSubmitting(false);
        }
    }

    async function handleChallenge(event) {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setStatus(null);

        try {
            await postJson('/api/v1/auth/login/challenge', { challenge_token: challenge, code });
            window.location.assign('/');
        } catch (error) {
            failed(error);
            if (error.errors?.challenge_token) {
                setChallenge(null);
            }
            setSubmitting(false);
        }
    }

    if (challenge) {
        return (
            <form onSubmit={handleChallenge} className="c-form" noValidate>
                <Notice>{t('Enter the 6-digit code from your authenticator app, or one of your recovery codes.')}</Notice>
                <Field
                    label={t('Authentication code')}
                    error={errors.code}
                    className="c-code-input"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    autoFocus
                    required
                    maxLength={32}
                    value={code}
                    onChange={(e) => setCode(e.target.value.trim())}
                />
                {status && <Notice type={status.type}>{status.message}</Notice>}
                <button type="submit" className="c-btn c-btn--block" disabled={submitting || code.length < 6}>
                    {submitting ? t('Checking…') : t('Verify and sign in')}
                </button>
                <button type="button" className="c-linkbtn" onClick={() => { setChallenge(null); setCode(''); setStatus(null); }}>
                    {t('Use a different account')}
                </button>
            </form>
        );
    }

    return (
        <form onSubmit={handleSubmit} className="c-form" noValidate>
            <Field
                label={t('Email')}
                error={errors.email}
                type="email"
                required
                autoComplete="email"
                inputMode="email"
                value={form.email}
                onChange={(e) => update('email', e.target.value)}
            />

            <PasswordField
                label={t('Password')}
                error={errors.password}
                required
                autoComplete="current-password"
                value={form.password}
                onChange={(e) => update('password', e.target.value)}
                labelAside={<button type="button" className="c-linkbtn" onClick={onForgot}>{t('Forgot password?')}</button>}
            />

            <label className="c-check">
                <input type="checkbox" checked={form.remember} onChange={(e) => update('remember', e.target.checked)} />
                <span>{t('Keep me signed in on this device')}</span>
            </label>

            <TurnstileWidget key={turnstileKey} siteKey={siteKey} onVerify={setTurnstileToken} onExpire={() => setTurnstileToken('')} />
            {errors.cf_turnstile_response && <small className="c-error">{errors.cf_turnstile_response[0]}</small>}

            {status && <Notice type={status.type}>{status.message}</Notice>}

            <button type="submit" className="c-btn c-btn--block" disabled={submitting}>
                {submitting ? t('Signing in…') : t('Sign in')}
            </button>

            <GoogleButton href={googleUrl} />
        </form>
    );
}
