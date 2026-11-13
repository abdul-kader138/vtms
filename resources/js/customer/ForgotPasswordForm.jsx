import { useState } from 'react';
import { postJson } from './api';
import { t } from './i18n';
import { Field, Icon, Notice } from './ui';

export default function ForgotPasswordForm({ onBack }) {
    const [email, setEmail] = useState('');
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(event) {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setStatus(null);

        try {
            const data = await postJson('/api/v1/auth/forgot-password', { email });
            setStatus({ type: 'success', message: data.message || t('If that email address is registered, a reset link has been sent.') });
        } catch (error) {
            setErrors(error.errors || {});
            setStatus({ type: 'error', message: error.message });
        } finally {
            setSubmitting(false);
        }
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
                autoFocus
                value={email}
                onChange={(e) => setEmail(e.target.value)}
            />

            {status && <Notice type={status.type}>{status.message}</Notice>}

            <button type="submit" className="c-btn c-btn--block" disabled={submitting || status?.type === 'success'}>
                {submitting ? t('Sending…') : t('Email me a reset link')}
            </button>
            <button type="button" className="c-btn c-btn--quiet c-btn--block" onClick={onBack}>
                <Icon name="arrowLeft" /> {t('Back to sign in')}
            </button>
        </form>
    );
}
