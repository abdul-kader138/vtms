import { useId, useState } from 'react';
import { t } from './i18n';

// One stroke icon set (Lucide geometry, ISC licence), 24px grid, 2px stroke.
const PATHS = {
    eye: <><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0" /><circle cx="12" cy="12" r="3" /></>,
    eyeOff: <><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c4.64 0 8.58 3.03 9.94 7.35a1 1 0 0 1 0 .7 10.8 10.8 0 0 1-1.55 2.86M6.61 6.61A10.75 10.75 0 0 0 2.06 12.35a1 1 0 0 0 0 .7A10.75 10.75 0 0 0 12 19a10.6 10.6 0 0 0 5.39-1.61" /><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24M2 2l20 20" /></>,
    check: <path d="M20 6 9 17l-5-5" />,
    alert: <><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" /></>,
    info: <><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></>,
    file: <><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" /><path d="M14 2v4a2 2 0 0 0 2 2h4M16 13H8M16 17H8M10 9H8" /></>,
    receipt: <><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z" /><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11" /></>,
    download: <><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m7 10 5 5 5-5M12 15V3" /></>,
    chevron: <path d="m6 9 6 6 6-6" />,
    logout: <><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5M21 12H9" /></>,
    mail: <><rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-10 6L2 7" /></>,
    arrowLeft: <path d="m12 19-7-7 7-7M19 12H5" />,
};

export function Icon({ name, label }) {
    return (
        <svg className="c-icon" viewBox="0 0 24 24" aria-hidden={label ? undefined : 'true'} role={label ? 'img' : undefined} aria-label={label}>
            {PATHS[name]}
        </svg>
    );
}

const NOTICE_ICON = { success: 'check', error: 'alert', danger: 'alert', warning: 'alert', info: 'info' };

export function Notice({ type = 'info', children }) {
    return (
        <div className={`c-notice c-notice--${type}`} role={type === 'error' || type === 'danger' ? 'alert' : 'status'}>
            <Icon name={NOTICE_ICON[type] ?? 'info'} />
            <span>{children}</span>
        </div>
    );
}

export function FieldError({ id, errors }) {
    return errors?.[0] ? <small className="c-error" id={id}>{errors[0]}</small> : null;
}

/** Text input with label + error wired for screen readers. */
export function Field({ label, error, hint, labelAside, children, ...input }) {
    const id = useId();
    const errorId = `${id}-error`;

    return (
        <div className="c-field">
            <div className="c-label-row">
                <label htmlFor={id}>{label}</label>
                {labelAside}
            </div>
            {children ?? (
                <input id={id} aria-invalid={error ? 'true' : undefined} aria-describedby={error ? errorId : undefined} {...input} />
            )}
            {hint && !error && <small className="c-hint">{hint}</small>}
            <FieldError id={errorId} errors={error} />
        </div>
    );
}

export function PasswordField({ label, error, labelAside, ...input }) {
    const id = useId();
    const errorId = `${id}-error`;
    const [visible, setVisible] = useState(false);

    return (
        <div className="c-field">
            <div className="c-label-row">
                <label htmlFor={id}>{label}</label>
                {labelAside}
            </div>
            <div className="c-password">
                <input
                    id={id}
                    type={visible ? 'text' : 'password'}
                    aria-invalid={error ? 'true' : undefined}
                    aria-describedby={error ? errorId : undefined}
                    {...input}
                />
                <button type="button" onClick={() => setVisible((v) => !v)} aria-label={visible ? t('Hide password') : t('Show password')} aria-pressed={visible}>
                    <Icon name={visible ? 'eyeOff' : 'eye'} />
                </button>
            </div>
            <FieldError id={errorId} errors={error} />
        </div>
    );
}

export function GoogleButton({ href }) {
    if (!href) {
        return null;
    }

    return (
        <>
            <div className="c-divider">{t('or')}</div>
            <a className="c-btn c-btn--quiet c-btn--block" href={href}>
                <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z" />
                    <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
                    <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" />
                    <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
                </svg>
                {t('Continue with Google')}
            </a>
        </>
    );
}
