import { createRoot } from 'react-dom/client';
import AuthPage from './customer/AuthPage';
import ResetPasswordForm from './customer/ResetPasswordForm';

const root = document.getElementById('customer-auth-root');

if (root) {
    const mode = root.dataset.mode;
    const siteKey = root.dataset.turnstileSiteKey || '';
    const phoneCountries = JSON.parse(root.dataset.phoneCountries || '[]');
    const defaultPhoneCountry = root.dataset.defaultPhoneCountry || '';
    const googleUrl = root.dataset.google || '';

    createRoot(root).render(
        mode === 'reset-password'
            ? <ResetPasswordForm />
            : <AuthPage initialMode={mode} siteKey={siteKey} googleUrl={googleUrl} phoneCountries={phoneCountries} defaultPhoneCountry={defaultPhoneCountry} />,
    );
}
