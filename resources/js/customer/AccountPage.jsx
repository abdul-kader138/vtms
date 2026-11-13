import { useEffect, useState } from 'react';
import { getJson, postJson, putJson } from './api';
import { locale, t } from './i18n';
import { Icon, Notice } from './ui';

function AccountBand({ appName, logo }) {
    async function logout() {
        await postJson('/api/v1/auth/logout', {}).catch(() => {});
        window.location.assign('/login');
    }

    async function switchLanguage(code) {
        await postJson('/locale', { locale: code }).catch(() => {});
        window.location.reload();
    }

    return <header className="c-band"><div className="c-band-inner">
        <a className="c-mark" href="/app/tax">{logo ? <img src={logo} alt="" /> : <span className="c-mark-square" aria-hidden="true">{appName.slice(0, 1).toUpperCase()}</span>}<span>{appName}</span></a>
        <nav className="c-account-nav"><a href="/app/tax">{t('My taxes')}</a><strong>{t('Account')}</strong></nav>
        <div className="c-band-actions"><div className="c-lang" role="group" aria-label={t('Language')}>
            {['it', 'en'].map((code) => <button key={code} type="button" aria-pressed={locale.startsWith(code)} onClick={() => switchLanguage(code)}>{code.toUpperCase()}</button>)}
        </div><button type="button" className="c-chip" onClick={logout}><Icon name="logout" /><span>{t('Sign out')}</span></button></div>
    </div></header>;
}

export default function AccountPage({ appName, logo }) {
    const [account, setAccount] = useState(null);
    const [calendar, setCalendar] = useState(null);
    const [form, setForm] = useState({});
    const [message, setMessage] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    async function load() {
        const [accountResponse, calendarResponse] = await Promise.all([getJson('/api/v1/account'), getJson('/api/v1/tax/calendar')]);
        const next = accountResponse.data ?? accountResponse;
        setAccount(next);
        setForm({ first_name: next.first_name ?? '', last_name: next.last_name ?? '', email: next.email ?? '', phone: next.phone ?? '', tax_id: next.tax_id ?? '', address: next.address ?? '', tax_reminders_enabled: next.tax_reminders_enabled ?? true });
        setCalendar(calendarResponse);
    }

    useEffect(() => {
        load().catch((nextError) => {
            if (nextError.status === 401) {
                window.location.assign('/login');
                return;
            }
            setError(nextError.message);
        });
    }, []);

    function update(name, value) {
        setForm((current) => ({ ...current, [name]: value }));
    }

    async function save(event) {
        event.preventDefault();
        setBusy(true);
        setMessage(null);
        setError(null);
        try {
            const response = await putJson('/api/v1/account', form);
            setAccount(response.data ?? response);
            setMessage(t('Account saved'));
        } catch (nextError) {
            setError(nextError.errors ? Object.values(nextError.errors).flat()[0] : nextError.message);
        } finally {
            setBusy(false);
        }
    }

    async function resetCalendar() {
        setBusy(true);
        try {
            setCalendar(await postJson('/api/v1/tax/calendar/reset', {}));
            setMessage(t('Calendar link replaced. Add the new link to your calendar app.'));
        } catch (nextError) {
            setError(nextError.message);
        } finally {
            setBusy(false);
        }
    }

    if (!account) {
        return <><AccountBand appName={appName} logo={logo} /><main className="c-page"><div className="c-skel" style={{ height: 360 }} /></main></>;
    }

    return <><AccountBand appName={appName} logo={logo} /><main className="c-page c-account-page">
        <div className="c-who"><h1>{t('Account')}</h1><p>{t('Your sign-in details and personal calendar')}</p></div>
        {message && <Notice type="success">{message}</Notice>}
        {error && <Notice type="error">{error}</Notice>}
        <div className="c-account-grid">
            <section className="c-account-section" aria-labelledby="identity-heading"><div className="c-section-head"><h2 id="identity-heading">{t('Personal details')}</h2></div>
                <form className="c-form" onSubmit={save}>
                    <div className="c-row"><label className="c-field"><span>{t('First name')}</span><input value={form.first_name} onChange={(event) => update('first_name', event.target.value)} required /></label><label className="c-field"><span>{t('Last name')}</span><input value={form.last_name} onChange={(event) => update('last_name', event.target.value)} required /></label></div>
                    <label className="c-field"><span>{t('Email')}</span><input type="email" value={form.email} onChange={(event) => update('email', event.target.value)} required /><small className="c-hint">{t('Changing email requires verification again.')}</small></label>
                    <label className="c-field"><span>{t('Phone')}</span><input value={form.phone} onChange={(event) => update('phone', event.target.value)} /></label>
                    <label className="c-field"><span>{t('Tax ID')}</span><input value={form.tax_id} onChange={(event) => update('tax_id', event.target.value)} /></label>
                    <label className="c-field"><span>{t('Address')}</span><textarea className="c-textarea" value={form.address} onChange={(event) => update('address', event.target.value)} rows="3" /></label>
                    <label className="c-check"><input type="checkbox" checked={Boolean(form.tax_reminders_enabled)} onChange={(event) => update('tax_reminders_enabled', event.target.checked)} />{t('Send me payment deadline reminders')}</label>
                    <button className="c-btn" type="submit" disabled={busy}>{busy ? t('Saving…') : t('Save changes')}</button>
                </form>
            </section>
            <section className="c-account-section" aria-labelledby="calendar-heading"><div className="c-section-head"><h2 id="calendar-heading">{t('Payment calendar')}</h2></div>
                <p className="c-account-copy">{t('Add your personal deadlines to Apple Calendar, Google Calendar or any app that supports subscriptions. It updates automatically.')}</p>
                <div className="c-calendar-actions"><a className="c-btn" href={calendar?.webcal_url}>{t('Add to calendar')}</a><a className="c-btn c-btn--quiet" href={calendar?.google_url} target="_blank" rel="noreferrer">{t('Add to Google Calendar')}</a></div>
                <label className="c-field"><span>{t('Calendar subscription link')}</span><input readOnly value={calendar?.url ?? ''} onFocus={(event) => event.target.select()} /></label>
                <button className="c-linkbtn" type="button" onClick={resetCalendar} disabled={busy}>{t('Create a new calendar link')}</button>
                <p className="c-hint">{t('Use this if the link has been shared or you no longer want the old link to work.')}</p>
            </section>
        </div>
    </main></>;
}
