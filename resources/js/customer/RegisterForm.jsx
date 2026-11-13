import { useState } from 'react';
import TurnstileWidget from './TurnstileWidget';
import { postJson } from './api';
import { t } from './i18n';

const EMPTY_FORM = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    marketing_opt_in: false,
};

export default function RegisterForm({ siteKey, phoneCountries, defaultPhoneCountry }) {
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
            await postJson('/api/v1/auth/register', {
                ...form,
                cf_turnstile_response: turnstileToken,
            });

            window.location.assign('/verify-email');
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
            <div className="auth-field-row">
                <label className="auth-field">
                    <span>{t('First name')}</span>
                    <input required value={form.first_name} onChange={(e) => update('first_name', e.target.value)} autoComplete="given-name" />
                    {errors.first_name && <small className="auth-error">{errors.first_name[0]}</small>}
                </label>

                <label className="auth-field">
                    <span>{t('Last name')}</span>
                    <input required value={form.last_name} onChange={(e) => update('last_name', e.target.value)} autoComplete="family-name" />
                    {errors.last_name && <small className="auth-error">{errors.last_name[0]}</small>}
                </label>
            </div>

            <label className="auth-field">
                <span>{t('Email')}</span>
                <input type="email" required value={form.email} onChange={(e) => update('email', e.target.value)} autoComplete="email" />
                {errors.email && <small className="auth-error">{errors.email[0]}</small>}
            </label>

            <div className="auth-field">
                <label htmlFor="register-phone">{t('Phone (optional)')}</label>
                <div className="auth-phone-row">
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
                    />
                </div>
                {errors.phone && <small className="auth-error">{errors.phone[0]}</small>}
            </div>

            <label className="auth-field">
                <span>{t('Password')}</span>
                <input
                    type="password"
                    required
                    value={form.password}
                    onChange={(e) => update('password', e.target.value)}
                    autoComplete="new-password"
                />
                {errors.password && <small className="auth-error">{errors.password[0]}</small>}
            </label>

            <label className="auth-field">
                <span>{t('Confirm password')}</span>
                <input
                    type="password"
                    required
                    value={form.password_confirmation}
                    onChange={(e) => update('password_confirmation', e.target.value)}
                    autoComplete="new-password"
                />
            </label>

            <label className="auth-checkbox">
                <input
                    type="checkbox"
                    checked={form.marketing_opt_in}
                    onChange={(e) => update('marketing_opt_in', e.target.checked)}
                />
                <span>{t('Send me course updates and offers')}</span>
            </label>

            <TurnstileWidget key={turnstileKey} siteKey={siteKey} onVerify={setTurnstileToken} onExpire={() => setTurnstileToken('')} />
            {errors.cf_turnstile_response && <small className="auth-error">{errors.cf_turnstile_response[0]}</small>}

            {status && <p className={`auth-status auth-status--${status.type}`}>{status.message}</p>}

            <button type="submit" disabled={submitting}>
                {submitting ? t('Creating account…') : t('Create account')}
            </button>
        </form>
    );
}
