import { useEffect, useState } from 'react';
import { postJson } from './api';
import { t } from './i18n';
import { Icon, Notice, PasswordField } from './ui';

export default function ResetPasswordForm() {
    const [email, setEmail] = useState('');
    const [token, setToken] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    // The reset email links here with these in the query string — see
    // App\Notifications\Auth\ResetPassword::resetUrl().
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        setEmail(params.get('email') || '');
        setToken(params.get('token') || '');
    }, []);

    async function handleSubmit(event) {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setStatus(null);

        try {
            const data = await postJson('/api/v1/auth/reset-password', {
                token,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });
            setStatus({ type: 'success', message: data.message || t('Your password has been reset.') });
        } catch (error) {
            setErrors(error.errors || {});
            setStatus({ type: 'error', message: error.message });
        } finally {
            setSubmitting(false);
        }
    }

    if (!token || !email) {
        return (
            <>
                <div>
                    <h2>{t('This link is incomplete')}</h2>
                    <p className="c-sub">{t('This password reset link is missing its token. Please request a new one.')}</p>
                </div>
                <a className="c-btn c-btn--block" href="/login">{t('Back to sign in')}</a>
            </>
        );
    }

    if (status?.type === 'success') {
        return (
            <>
                <div className="c-status-icon" aria-hidden="true"><Icon name="check" /></div>
                <div>
                    <h2>{t('Password changed')}</h2>
                    <p className="c-sub">{status.message}</p>
                </div>
                <a className="c-btn c-btn--block" href="/login">{t('Go to sign in')}</a>
            </>
        );
    }

    return (
        <>
            <div>
                <h2>{t('Choose a new password')}</h2>
                <p className="c-sub">{t('For :email', { email })}</p>
            </div>
            <form onSubmit={handleSubmit} className="c-form" noValidate>
                <PasswordField label={t('New password')} error={errors.password} required autoComplete="new-password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)} />
                <small className="c-hint" style={{ marginTop: -10 }}>{t('At least 8 characters, with upper and lower case letters and a number.')}</small>
                <PasswordField label={t('Confirm new password')} required autoComplete="new-password" value={passwordConfirmation} onChange={(e) => setPasswordConfirmation(e.target.value)} />

                {errors.email && <Notice type="error">{errors.email[0]}</Notice>}
                {status && <Notice type={status.type}>{status.message}</Notice>}

                <button type="submit" className="c-btn c-btn--block" disabled={submitting}>
                    {submitting ? t('Saving…') : t('Save new password')}
                </button>
            </form>
        </>
    );
}
