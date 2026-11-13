import { useState } from 'react';
import ForgotPasswordForm from './ForgotPasswordForm';
import LoginForm from './LoginForm';
import RegisterForm from './RegisterForm';
import { t } from './i18n';

const HEADINGS = {
    login: ['Welcome back', 'Sign in to see what to pay next.'],
    register: ['Create your account', 'Use the email your accountant has on file — it makes linking your tax records quicker.'],
    forgot: ['Reset your password', 'We will email you a link to choose a new one.'],
};

export default function AuthPage({ initialMode, siteKey, googleUrl, phoneCountries, defaultPhoneCountry }) {
    const [mode, setMode] = useState(initialMode === 'register' ? 'register' : 'login');
    const [title, subtitle] = HEADINGS[mode];

    function go(next) {
        setMode(next);
        // Keep the address bar honest so a refresh or a shared link opens the same tab.
        const path = next === 'register' ? '/register' : '/login';
        if (next !== 'forgot' && window.location.pathname !== path) {
            window.history.replaceState(null, '', path);
        }
    }

    return (
        <>
            <div>
                <h2>{t(title)}</h2>
                <p className="c-sub">{t(subtitle)}</p>
            </div>

            {mode === 'forgot' ? (
                <ForgotPasswordForm onBack={() => go('login')} />
            ) : (
                <>
                    <div className="c-tabs" role="tablist" aria-label={t('Account')}>
                        <button type="button" role="tab" aria-selected={mode === 'login'} onClick={() => go('login')}>{t('Sign in')}</button>
                        <button type="button" role="tab" aria-selected={mode === 'register'} onClick={() => go('register')}>{t('Create account')}</button>
                    </div>
                    <div role="tabpanel">
                        {mode === 'login'
                            ? <LoginForm siteKey={siteKey} googleUrl={googleUrl} onForgot={() => go('forgot')} />
                            : <RegisterForm siteKey={siteKey} googleUrl={googleUrl} phoneCountries={phoneCountries} defaultPhoneCountry={defaultPhoneCountry} />}
                    </div>
                </>
            )}
        </>
    );
}
