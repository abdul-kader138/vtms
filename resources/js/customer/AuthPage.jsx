import { useState } from 'react';
import LoginForm from './LoginForm';
import RegisterForm from './RegisterForm';
import { t } from './i18n';

export default function AuthPage({ initialMode, siteKey, phoneCountries, defaultPhoneCountry }) {
    const [mode, setMode] = useState(initialMode === 'register' ? 'register' : 'login');

    return (
        <div className="auth-card">
            <div className="auth-tabs">
                <button type="button" className={mode === 'login' ? 'active' : ''} onClick={() => setMode('login')}>
                    {t('Sign in')}
                </button>
                <button type="button" className={mode === 'register' ? 'active' : ''} onClick={() => setMode('register')}>
                    {t('Create account')}
                </button>
            </div>

            {mode === 'login' ? <LoginForm siteKey={siteKey} /> : <RegisterForm siteKey={siteKey} phoneCountries={phoneCountries} defaultPhoneCountry={defaultPhoneCountry} />}
        </div>
    );
}
