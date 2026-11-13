import { useState } from 'react';
import TurnstileWidget from './TurnstileWidget';
import { postJson } from './api';
import { t } from './i18n';
import { Field, FieldError, GoogleButton, Notice, PasswordField } from './ui';

const EMPTY_FORM = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    marketing_opt_in: false,
};

export default function RegisterForm({ siteKey, googleUrl, phoneCountries, defaultPhoneCountry }) {
    const [form, setForm] = useState({ ...EMPTY_FORM, phone_country: defaultPhoneCountry });
    const [turnstileToken, setTurnstileToken] = useState('');
    const [turnstileKey, setTurnstileKey] = useState(0);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const phoneExample = phoneCountries.find((country) => country.code === form.phone_country)?.example;

    function update(field, value) {
        setForm((prev) => ({ ...prev, [field]: value }));
    }

    async function handleSubmit(event) {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setStatus(null);

        try {
            await postJson('/api/v1/auth/register', { ...form, cf_turnstile_response: turnstileToken });
            window.location.assign('/verify-email');
        } catch (error) {
            setErrors(error.errors || {});
            setStatus({ type: 'error', message: error.message });
            // Tokens are single-use, so a rejected submit needs a fresh challenge.
            setTurnstileToken('');
            setTurnstileKey((key) => key + 1);
            setSubmitting(false);
        }
    }

    return (
        <form onSubmit={handleSubmit} className="c-form" noValidate>
            <div className="c-row">
                <Field label={t('First name')} error={errors.first_name} required autoComplete="given-name" value={form.first_name} onChange={(e) => update('first_name', e.target.value)} />
                <Field label={t('Last name')} error={errors.last_name} required autoComplete="family-name" value={form.last_name} onChange={(e) => update('last_name', e.target.value)} />
            </div>

            <Field label={t('Email')} error={errors.email} type="email" required autoComplete="email" inputMode="email" value={form.email} onChange={(e) => update('email', e.target.value)} />

            <div className="c-field">
                <label htmlFor="register-phone">{t('Phone (optional)')}</label>
                <div className="c-phone">
                    <select aria-label={t('Country code')} value={form.phone_country} onChange={(e) => update('phone_country', e.target.value)}>
                        {phoneCountries.map((country) => (
                            <option key={country.code} value={country.code}>{country.name} (+{country.dial})</option>
                        ))}
                    </select>
                    <input
                        id="register-phone"
                        type="tel"
                        value={form.phone}
                        onChange={(e) => update('phone', e.target.value)}
                        autoComplete="tel-national"
                        placeholder={phoneExample}
                        aria-invalid={errors.phone ? 'true' : undefined}
                    />
                </div>
                <FieldError errors={errors.phone} />
            </div>

            <PasswordField label={t('Password')} error={errors.password} required autoComplete="new-password" value={form.password} onChange={(e) => update('password', e.target.value)} />
            <small className="c-hint" style={{ marginTop: -10 }}>{t('At least 8 characters, with upper and lower case letters and a number.')}</small>
            <PasswordField label={t('Confirm password')} required autoComplete="new-password" value={form.password_confirmation} onChange={(e) => update('password_confirmation', e.target.value)} />

            <label className="c-check">
                <input type="checkbox" checked={form.marketing_opt_in} onChange={(e) => update('marketing_opt_in', e.target.checked)} />
                <span>{t('Send me updates and offers')}</span>
            </label>

            <TurnstileWidget key={turnstileKey} siteKey={siteKey} onVerify={setTurnstileToken} onExpire={() => setTurnstileToken('')} />
            {errors.cf_turnstile_response && <small className="c-error">{errors.cf_turnstile_response[0]}</small>}

            {status && <Notice type={status.type}>{status.message}</Notice>}

            <button type="submit" className="c-btn c-btn--block" disabled={submitting}>
                {submitting ? t('Creating account…') : t('Create account')}
            </button>

            <p className="c-foot">{t('After you confirm your email, your accountant links the account to your tax records.')}</p>

            <GoogleButton href={googleUrl} />
        </form>
    );
}
