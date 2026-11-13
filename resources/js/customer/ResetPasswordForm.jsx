import { useEffect, useState } from 'react';
import { postJson } from './api';
import { t } from './i18n';

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
            <div className="auth-card">
                <p className="auth-status auth-status--error">{t('This password reset link is missing its token. Please request a new one.')}</p>
                <a href="/login" style={{ color: '#a5b4fc' }}>{t('Back to sign in')}</a>
            </div>
        );
    }

    if (status?.type === 'success') {
        return (
            <div className="auth-card" style={{ textAlign: 'center' }}>
                <p className="auth-status auth-status--success">{status.message}</p>
                <a href="/login" style={{ color: '#a5b4fc' }}>{t('Go to sign in')}</a>
            </div>
        );
    }

    return (
        <div className="auth-card">
            <h1 style={{ fontSize: '1.1rem', margin: '0 0 1rem', color: '#fff' }}>{t('Reset your password')}</h1>
            <form onSubmit={handleSubmit} className="auth-form">
                <label className="auth-field">
                    <span>{t('New password')}</span>
                    <input
                        type="password"
                        required
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        autoComplete="new-password"
                    />
                    {errors.password && <small className="auth-error">{errors.password[0]}</small>}
                </label>

                <label className="auth-field">
                    <span>{t('Confirm new password')}</span>
                    <input
                        type="password"
                        required
                        value={passwordConfirmation}
                        onChange={(e) => setPasswordConfirmation(e.target.value)}
                        autoComplete="new-password"
                    />
                </label>

                {status && <p className={`auth-status auth-status--${status.type}`}>{status.message}</p>}

                <button type="submit" disabled={submitting}>
                    {submitting ? t('Resetting…') : t('Reset password')}
                </button>
            </form>
        </div>
    );
}
