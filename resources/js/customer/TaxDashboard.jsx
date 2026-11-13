import { useEffect, useMemo, useState } from 'react';
import { getJson, postJson, postMultipart } from './api';
import { locale, t } from './i18n';
import { Icon, Notice } from './ui';
import AccountPage from './AccountPage';

const LANGUAGES = [['it', 'IT'], ['en', 'EN']];
const SOON_DAYS = 14;

let money;
try {
    money = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR', useGrouping: 'always' });
} catch {
    money = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' });
}

const eur = (value) => (value === null || value === undefined ? '—' : money.format(Number(value)));
const pct = (value) => `${Number(value).toLocaleString(locale, { maximumFractionDigits: 2 })}%`;
const day = (value, opts = { day: 'numeric', month: 'short', year: 'numeric' }) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString(locale, opts) : '—');

function todayIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function daysUntil(value) {
    return Math.round((new Date(`${value}T00:00:00`) - new Date(`${todayIso()}T00:00:00`)) / 86400000);
}

const KIND = {
    tax: 'Flat tax',
    inps: 'INPS',
    inail: 'INAIL',
    fattura_tra_privati: 'Stamp duty',
    diritto_annuale: 'Chamber of Commerce fee',
};

// ── Band ────────────────────────────────────────────────────────────────

function Band({ appName, logo, title, years, year, onYear, loading }) {
    async function logout() {
        await postJson('/api/v1/auth/logout', {}).catch(() => {});
        window.location.assign('/login');
    }

    async function switchLanguage(code) {
        await postJson('/locale', { locale: code }).catch(() => {});
        window.location.reload();
    }

    return (
        <header className="c-band">
            <div className="c-band-inner">
                <a className="c-mark" href="/app/tax">
                    {logo ? <img src={logo} alt="" /> : <span className="c-mark-square" aria-hidden="true">{appName.slice(0, 1).toUpperCase()}</span>}
                    <span>{appName}</span>
                </a>
                {title && <span className="c-band-title">{title}</span>}
                <div className="c-band-actions">
                    {years && (
                        <select aria-label={t('Tax year')} value={year ?? ''} onChange={(e) => onYear(Number(e.target.value))} disabled={loading}>
                            {years.map((y) => <option key={y} value={y}>{y}</option>)}
                        </select>
                    )}
                    <div className="c-lang" role="group" aria-label={t('Language')}>
                        {LANGUAGES.map(([code, label]) => (
                            <button key={code} type="button" aria-pressed={locale.startsWith(code)} onClick={() => switchLanguage(code)}>{label}</button>
                        ))}
                    </div>
                    <button type="button" className="c-chip" onClick={logout} aria-label={t('Sign out')}>
                        <Icon name="logout" /> <span>{t('Sign out')}</span>
                    </button>
                </div>
            </div>
        </header>
    );
}

// ── Next stop ───────────────────────────────────────────────────────────

function NextStop({ stops, summary, year }) {
    const overdue = stops.filter((p) => p.effective_status === 'overdue');
    const upcoming = stops.filter((p) => p.effective_status === 'pending');

    if (overdue.length > 0) {
        const total = overdue.reduce((sum, p) => sum + p.outstanding, 0);
        return (
            <section className="c-next c-next--overdue" aria-labelledby="next-heading">
                <div>
                    <h2 className="c-next-label" id="next-heading">
                        <span className="c-next-dot" aria-hidden="true" />
                        {overdue.length === 1 ? t('1 payment is overdue') : t(':n payments are overdue', { n: overdue.length })}
                    </h2>
                    <div className="c-next-amount c-num">{eur(total)}</div>
                    <ul className="c-next-items">
                        {overdue.slice(0, 4).map((p) => (
                            <li key={`${p.type}-${p.id}`}>
                                <span className="c-code">{p.f24_code ?? '—'}</span>
                                <span>{p.description}</span>
                                <span className="c-hint c-num">{t('due :date', { date: day(p.due_date) })} · {eur(p.outstanding)}</span>
                            </li>
                        ))}
                        {overdue.length > 4 && <li className="c-hint">{t('and :n more on the line below', { n: overdue.length - 4 })}</li>}
                    </ul>
                    <p className="c-hint" style={{ marginTop: 12 }}>
                        {t('Pay as soon as you can: interest and a small penalty grow with time. Your accountant can prepare an F24 with the exact amount.')}
                    </p>
                    {upcoming[0] && (
                        <p className="c-next-then">
                            <strong>{t('Then next')}</strong>
                            <span>{day(upcoming[0].due_date, { day: 'numeric', month: 'short' })}</span>
                            <span className="c-code">{upcoming[0].f24_code ?? '—'}</span>
                            <span>{upcoming[0].description}</span>
                            <span className="c-num">{eur(upcoming[0].outstanding)}</span>
                            <span className="c-hint">{t('in :n days', { n: daysUntil(upcoming[0].due_date) })}</span>
                        </p>
                    )}
                    <div className="c-next-actions">
                        <a className="c-btn c-btn--quiet c-btn--small" href="#how-to-pay">{t('How to pay with F24')}</a>
                    </div>
                </div>
                <div className="c-next-side">
                    <span>{t('Still to pay in :year', { year })}</span>
                    <strong className="c-num">{eur(summary.total_due)}</strong>
                </div>
            </section>
        );
    }

    if (upcoming.length === 0) {
        return (
            <section className="c-next c-next--clear" aria-labelledby="next-heading">
                <div>
                    <h2 className="c-next-label" id="next-heading"><Icon name="check" /> {t('All paid for :year', { year })}</h2>
                    <div className="c-next-amount c-num">{eur(summary.total_paid)}</div>
                    <p className="c-hint" style={{ marginTop: 8 }}>{t('Nothing is due right now. New deadlines appear here when your accountant updates the year.')}</p>
                </div>
            </section>
        );
    }

    const next = upcoming[0];
    const sameDay = upcoming.filter((p) => p.due_date === next.due_date);
    const days = daysUntil(next.due_date);

    return (
        <section className="c-next" aria-labelledby="next-heading">
            <div>
                <h2 className="c-next-label" id="next-heading">
                    <span className="c-next-dot" aria-hidden="true" />
                    {t('Next stop')} · {day(next.due_date, { weekday: 'long', day: 'numeric', month: 'long' })}
                    <span className={days <= SOON_DAYS ? 'c-state c-state--soon' : 'c-state c-state--pending'}>
                        {days === 0 ? t('today') : days === 1 ? t('tomorrow') : t('in :n days', { n: days })}
                    </span>
                </h2>
                <div className="c-next-amount c-num">{eur(sameDay.reduce((sum, p) => sum + p.outstanding, 0))}</div>
                <ul className="c-next-items">
                    {sameDay.map((p) => (
                        <li key={`${p.type}-${p.id}`}>
                            <span className="c-code">{p.f24_code ?? '—'}</span>
                            <span>{p.description}</span>
                            {sameDay.length > 1 && <span className="c-hint c-num">{eur(p.outstanding)}</span>}
                        </li>
                    ))}
                </ul>
                <div className="c-next-actions">
                    <a className="c-btn c-btn--quiet c-btn--small" href="#how-to-pay">{t('How to pay with F24')}</a>
                </div>
            </div>
            <div className="c-next-side">
                <span>{t('Still to pay in :year', { year })}</span>
                <strong className="c-num">{eur(summary.total_due)}</strong>
                <span className="c-num">{t(':amount paid', { amount: eur(summary.total_paid) })}</span>
            </div>
        </section>
    );
}

// ── The line ────────────────────────────────────────────────────────────

const STATE_LABEL = { paid: 'Paid', overdue: 'Overdue', pending: 'Upcoming', cancelled: 'Cancelled' };

function Stop({ stop, isNext, onReported }) {
    const [open, setOpen] = useState(false);
    const [reporting, setReporting] = useState(false);
    const [busy, setBusy] = useState(false);
    const [reportError, setReportError] = useState(null);
    const [file, setFile] = useState(null);
    const [paidDate, setPaidDate] = useState(todayIso());
    const [paidAmount, setPaidAmount] = useState(String(stop.outstanding ?? ''));
    const status = stop.effective_status;
    const days = daysUntil(stop.due_date);
    const soon = status === 'pending' && days <= SOON_DAYS;
    const partly = stop.paid_amount > 0 && stop.outstanding > 0;
    const canAct = status === 'pending' || status === 'overdue';
    const todayCost = stop.late?.total ?? stop.outstanding;

    async function reportPaid(event) {
        event.preventDefault();
        setBusy(true);
        setReportError(null);

        try {
            const form = new FormData();
            form.append('paid_date', paidDate);
            form.append('paid_amount', paidAmount);
            form.append('receipt', file);
            await postMultipart(`/api/v1/tax/payments/${stop.type}/${stop.id}/report-paid`, form);
            setReporting(false);
            onReported();
        } catch (error) {
            setReportError(error.errors?.receipt?.[0] ?? error.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <li className={`c-stop c-stop--${status}${isNext ? ' c-stop--next' : ''}`} data-open={open}>
            <button type="button" className="c-stop-btn" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
                <span className="c-stop-date">
                    {day(stop.due_date, { day: 'numeric', month: 'short' })}
                    <small>{day(stop.due_date, { year: 'numeric' })}</small>
                </span>
                <span className="c-stop-dot" aria-hidden="true" />
                <span className="c-stop-main">
                    <span className="c-stop-title">{stop.description}</span>
                    <span className="c-stop-meta">
                        {stop.f24_code ? <span className="c-code">{stop.f24_code}</span> : <span className="c-code c-code--none">F24</span>}
                        <span className={`c-state c-state--${soon ? 'soon' : status}`}>
                            {status === 'paid' && <Icon name="check" />}
                            {soon ? (days === 0 ? t('Due today') : t('Due in :n days', { n: days })) : t(STATE_LABEL[status] ?? status)}
                        </span>
                        {stop.receipt_url && <span>{t('Receipt')}</span>}
                    </span>
                </span>
                <span className="c-stop-amount">
                    {eur(status === 'paid' ? stop.paid_amount : stop.outstanding)}
                    {partly && <small>{t('of :amount', { amount: eur(stop.amount) })}</small>}
                </span>
            </button>
            <div className="c-stop-detail">
                <div>
                    <dl>
                        <dt>{t('What')}</dt><dd>{t(KIND[stop.type] ?? stop.type)}</dd>
                        {stop.f24_code && <><dt>{t('F24 code')}</dt><dd>{stop.f24_code}{stop.f24_section ? ` · ${t('section')} ${stop.f24_section}` : ''}</dd></>}
                        <dt>{t('Due')}</dt><dd>{day(stop.due_date, { day: 'numeric', month: 'long', year: 'numeric' })}</dd>
                        <dt>{t('Amount')}</dt><dd>{eur(stop.amount)}</dd>
                        {stop.paid_amount > 0 && <><dt>{t('Paid')}</dt><dd>{eur(stop.paid_amount)}{stop.paid_date ? ` · ${day(stop.paid_date)}` : ''}</dd></>}
                        {stop.outstanding > 0 && status !== 'cancelled' && <><dt>{t('Left to pay')}</dt><dd>{eur(stop.outstanding)}</dd></>}
                        {stop.receipt_url && <><dt>{t('Receipt')}</dt><dd><a href={stop.receipt_url}>{t('Download')}</a></dd></>}
                        {canAct && <><dt>{t('Cost if paid today')}</dt><dd className={status === 'overdue' ? 'c-detail-overdue' : ''}>{eur(todayCost)}{stop.late && <small className="c-detail-note">{t('includes :amount in interest and penalty', { amount: eur((Number(stop.late.interest) || 0) + (Number(stop.late.penalty) || 0)) })}</small>}</dd></>}
                    </dl>
                    {canAct && <div className="c-stop-actions">
                        <a className="c-btn c-btn--small" href={`${stop.f24_sheet_url}?pay_on=${todayIso()}`} target="_blank" rel="noreferrer"><Icon name="file" />{t('Download F24')}</a>
                        {!stop.reported_paid_at && <button type="button" className="c-btn c-btn--quiet c-btn--small" onClick={() => setReporting((value) => !value)}>{t("I've paid")}</button>}
                    </div>}
                    {stop.reported_paid_at && <Notice type="info">{t('Receipt sent on :date. Your accountant will confirm the payment.', { date: day(stop.reported_paid_date) })}</Notice>}
                    {reporting && <form className="c-paid-form" onSubmit={reportPaid}>
                        <div className="c-row">
                            <label className="c-field"><span>{t('Paid on')}</span><input type="date" value={paidDate} max={todayIso()} onChange={(event) => setPaidDate(event.target.value)} required /></label>
                            <label className="c-field"><span>{t('Amount paid')}</span><input type="number" min="0.01" step="0.01" value={paidAmount} onChange={(event) => setPaidAmount(event.target.value)} required /></label>
                        </div>
                        <label className="c-field"><span>{t('Receipt PDF or image')}</span><input type="file" accept="application/pdf,image/jpeg,image/png" onChange={(event) => setFile(event.target.files?.[0] ?? null)} required /></label>
                        {reportError && <Notice type="error">{reportError}</Notice>}
                        <button type="submit" className="c-btn c-btn--small" disabled={busy || !file}>{busy ? t('Sending…') : t('Send receipt')}</button>
                    </form>}
                </div>
            </div>
        </li>
    );
}

function Line({ stops, year, nextKey, onReported }) {
    if (stops.length === 0) {
        return <p className="c-empty">{t('No payments are scheduled for :year yet. They appear here once your accountant calculates the year.', { year })}</p>;
    }

    const today = todayIso();
    let marker = stops.findIndex((s) => s.due_date >= today);
    const anyOpen = stops.some((s) => s.effective_status === 'pending' || s.effective_status === 'overdue');
    if (marker === -1) {
        marker = anyOpen ? stops.length : null;
    } else if (marker === 0 && year < new Date().getFullYear()) {
        marker = null;
    }

    const items = [];
    stops.forEach((stop, i) => {
        if (i === marker) {
            items.push(<Today key="today" />);
        }
        items.push(<Stop key={`${stop.type}-${stop.id}`} stop={stop} isNext={`${stop.type}-${stop.id}` === nextKey} onReported={onReported} />);
    });
    if (marker === stops.length) {
        items.push(<Today key="today" />);
    }

    return <ol className="c-line">{items}</ol>;
}

function Today() {
    return (
        <li className="c-today" aria-label={t('Today')}>
            <span>{t('Today')}<small>{day(todayIso(), { day: 'numeric', month: 'short' })}</small></span>
            <i aria-hidden="true" />
            <span />
        </li>
    );
}

// ── Your year ───────────────────────────────────────────────────────────

function Meter({ data }) {
    const limit = data.customer.revenue_limit || 85000;
    const actual = data.calculated ? Number(data.tax.total_sales) : Number(data.estimate?.total_sales ?? data.prediction?.current_total_sales ?? 0);
    const projected = data.prediction && data.prediction.missing_months.length > 0 ? Number(data.prediction.predicted_total_sales) : null;
    const peak = Math.max(actual, projected ?? 0);
    const tone = peak > limit ? 'over' : peak > limit * 0.8 ? 'warn' : '';
    const width = (v) => `${Math.min(100, (v / limit) * 100)}%`;

    return (
        <div className={`c-meter${tone ? ` c-meter--${tone}` : ''}`}>
            <div className="c-meter-figures">
                <strong className="c-num">{eur(actual)}</strong>
                <span className="c-num">{t('of :limit limit', { limit: eur(limit) })}</span>
            </div>
            <div className="c-meter-track" role="meter" aria-valuemin={0} aria-valuemax={limit} aria-valuenow={actual} aria-label={t('Revenue against the limit')}>
                {projected !== null && <span className="c-meter-proj" style={{ width: width(projected) }} />}
                <span className="c-meter-bar" style={{ width: width(actual) }} />
            </div>
            <p className="c-meter-note">
                {projected !== null
                    ? t('At this pace you reach :amount by December (:p of the limit).', { amount: eur(projected), p: pct(Math.round((projected / limit) * 100)) })
                    : t(':p of the limit used.', { p: pct(Math.round((actual / limit) * 100)) })}
            </p>
        </div>
    );
}

function Figures({ data }) {
    const { tax, inps, inail, fattura_tra_privati: stamp } = data;

    if (!data.calculated) {
        return data.estimate ? (
            <>
                <Notice>{t('Estimate — your accountant has not finalised :year yet.', { year: data.year })}</Notice>
                <dl className="c-figures">
                    <dt>{t('Revenue so far')}</dt><dd>{eur(data.estimate.total_sales)}</dd>
                    <dt>{t('Taxable income so far')}</dt><dd>{eur(data.estimate.taxable_income)}</dd>
                    <dt className="is-total">{t('Estimated flat tax so far')}</dt><dd className="is-total">{eur(data.estimate.estimated_tax)}</dd>
                    {data.prediction && data.prediction.missing_months.length > 0 && (
                        <><dt>{t('Projected flat tax for the year')}</dt><dd>{eur(data.prediction.predicted_tax_due)}</dd></>
                    )}
                </dl>
            </>
        ) : <p className="c-empty">{t('No income has been recorded for :year yet.', { year: data.year })}</p>;
    }

    const deducted = Number(tax.inps_deducted) > 0;

    return (
        <dl className="c-figures">
            <dt>{t('Revenue')}</dt><dd>{eur(tax.total_sales)}</dd>
            <dt>{t('Profit estimate (coefficient :c)', { c: pct(tax.coefficient_used) })}</dt><dd>{eur(tax.gross_income)}</dd>
            {deducted && <><dt>{t('INPS paid in :y, deducted', { y: data.year })}</dt><dd>− {eur(tax.inps_deducted)}</dd></>}
            <dt>{t('Taxable income')}</dt><dd>{eur(tax.taxable_income)}</dd>
            <dt className="is-total">{t('Flat tax at :r', { r: pct(tax.tax_rate_used) })}</dt><dd className="is-total">{eur(tax.tax_due)}</dd>
            <dt>{t('Advances already paid')}</dt><dd>{eur(tax.advance_payments_made)}</dd>
            <dt>{t('Balance to pay')}</dt><dd>{eur(tax.balance_payment)}</dd>
            {(inps || inail || stamp) && <div className="c-figures-group">{t('Contributions and fees')}</div>}
            {inps && <><dt>{t('INPS')}{Number(inps.discount_percentage) > 0 ? ` · ${t(':p reduction', { p: pct(inps.discount_percentage) })}` : ''}</dt><dd>{eur(inps.inps_amount_after_discount)}</dd></>}
            {inail && <><dt>{t('INAIL')}</dt><dd>{eur(inail.inail_final_amount)}</dd></>}
            {stamp && <><dt>{t('Stamp duty · :n invoices', { n: stamp.total_invoices })}</dt><dd>{eur(stamp.total_payment_amount)}</dd></>}
        </dl>
    );
}

function Documents({ data }) {
    const receipts = data.payments.filter((p) => p.receipt_url);

    if (!data.annual_report_url && receipts.length === 0) {
        return <p className="c-empty">{t('No documents for :year yet. The income statement appears once the year is calculated.', { year: data.year })}</p>;
    }

    return (
        <ul className="c-docs">
            {data.annual_report_url && (
                <li>
                    <a href={data.annual_report_url}>
                        <Icon name="file" />
                        <span>{t('Income statement :year', { year: data.year })}<small>{t('Conto economico · PDF')}</small></span>
                        <Icon name="download" />
                    </a>
                </li>
            )}
            {receipts.map((p) => (
                <li key={`${p.type}-${p.id}`}>
                    <a href={p.receipt_url}>
                        <Icon name="receipt" />
                        <span>{t('Receipt · :what', { what: p.description })}<small>{p.paid_date ? t('paid :date', { date: day(p.paid_date) }) : day(p.due_date)}</small></span>
                        <Icon name="download" />
                    </a>
                </li>
            ))}
        </ul>
    );
}

const EXPLAIN = {
    tax: {
        title: 'Flat tax (imposta sostitutiva)',
        body: [
            'In the forfettario regime one tax replaces income tax and regional taxes. Your profit is worked out from revenue with a fixed coefficient for your activity, and taxed at 15% — or 5% for the first five years when you qualify.',
            'You pay the balance for the year and advances towards the next one. The line shows each deadline.',
        ],
        codes: ['1792', '1790', '1791'],
    },
    inps: {
        title: 'INPS (social security)',
        body: [
            'Your pension contributions. Artisans and traders pay fixed contributions in four instalments, plus a percentage on income above the minimum; freelancers in Gestione Separata pay a percentage of income.',
        ],
    },
    inail: {
        title: 'INAIL (workplace insurance)',
        body: ['Insurance against accidents at work, required for some trades. It is paid once a year.'],
    },
    fattura_tra_privati: {
        title: 'Stamp duty (imposta di bollo)',
        body: ['€2 on every invoice over €77.47, because forfettario invoices carry no VAT. The Agenzia delle Entrate collects it every quarter.'],
        codes: ['2521', '2522', '2523', '2524'],
    },
    diritto_annuale: {
        title: 'Chamber of Commerce fee (Diritto Annuale)',
        body: ['A yearly fee for businesses registered with the Camera di Commercio.'],
        codes: ['3850'],
    },
};

function Explanations({ stops }) {
    const types = Object.keys(EXPLAIN).filter((type) => stops.some((s) => s.type === type));
    const codesFor = (type) => EXPLAIN[type].codes ?? [...new Set(stops.filter((s) => s.type === type).map((s) => s.f24_code).filter(Boolean))];

    return (
        <div className="c-explain">
            {types.map((type) => (
                <details key={type}>
                    <summary>{t(EXPLAIN[type].title)}<Icon name="chevron" /></summary>
                    <div className="c-explain-body">
                        {EXPLAIN[type].body.map((p) => <p key={p}>{t(p)}</p>)}
                        {codesFor(type).length > 0 && <div className="c-codes">{codesFor(type).map((c) => <span key={c} className="c-code">{c}</span>)}</div>}
                    </div>
                </details>
            ))}
            <details id="how-to-pay">
                <summary>{t('How do I pay?')}<Icon name="chevron" /></summary>
                <div className="c-explain-body">
                    <p>{t('Taxes and contributions are paid with the F24 form: in your bank\'s home banking, at a post office, or on the Agenzia delle Entrate website.')}</p>
                    <p>{t('Use the code shown on each stop and the year it refers to. After paying, send the receipt to your accountant so it is recorded here.')}</p>
                </div>
            </details>
        </div>
    );
}

// ── Page ────────────────────────────────────────────────────────────────

function Waiting({ appName, logo, user }) {
    return (
        <>
            <Band appName={appName} logo={logo} />
            <main className="c-page">
                <div className="c-waiting">
                    <h1>{user?.first_name ? t('Almost there, :name', { name: user.first_name }) : t('Almost there')}</h1>
                    <p>{t('Your account works. Your accountant now needs to link it to your tax records — then your payments, deadlines and documents appear here.')}</p>
                    <ol className="c-route c-route--ink" aria-label={t('Progress')}>
                        <li className="done"><span>{t('Account created')}<small>{user?.email}</small></span></li>
                        <li className="here"><span>{t('Your accountant links your records')}<small>{t('Nothing to do on your side. If it takes more than a couple of working days, contact the office with this email address.')}</small></span></li>
                        <li><span>{t('See what to pay and when')}<small>{t('Next payment, F24 codes, your year and documents')}</small></span></li>
                    </ol>
                    <div><button type="button" className="c-btn c-btn--quiet" onClick={() => window.location.reload()}>{t('Check again')}</button></div>
                </div>
            </main>
        </>
    );
}

function Skeleton({ appName, logo }) {
    return (
        <>
            <Band appName={appName} logo={logo} />
            <main className="c-page" aria-busy="true" aria-label={t('Loading')}>
                <div className="c-skel" style={{ height: 36, width: 280, marginBottom: 20 }} />
                <div className="c-skel" style={{ height: 170 }} />
                <div className="c-grid">
                    <div className="c-skel" style={{ height: 420 }} />
                    <div className="c-skel" style={{ height: 300 }} />
                </div>
            </main>
        </>
    );
}

export default function TaxDashboard({ appName, logo, view = 'tax' }) {
    const [meta, setMeta] = useState(null);
    const [year, setYear] = useState(null);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    function handleError(err) {
        if (err.status === 401) {
            window.location.assign('/login');
            return;
        }
        setError(err);
    }

    useEffect(() => {
        if (view === 'account') {
            return;
        }
        getJson('/api/v1/tax/years')
            .then((result) => {
                setMeta(result);
                setYear(result.default_year);
            })
            .catch((err) => {
                handleError(err);
                setLoading(false);
            });
    }, []);

    useEffect(() => {
        if (view === 'account') {
            return;
        }
        if (year === null) {
            return;
        }
        setLoading(true);
        getJson(`/api/v1/tax/${year}`)
            .then((result) => {
                setData(result);
                setError(null);
            })
            .catch(handleError)
            .finally(() => setLoading(false));
    }, [year]);

    if (view === 'account') {
        return <AccountPage appName={appName} logo={logo} />;
    }

    // The line runs in date order, like the stations on a map.
    const stops = useMemo(() => (data ? [...data.payments].sort((a, b) => a.due_date.localeCompare(b.due_date)) : []), [data]);
    const nextKey = useMemo(() => {
        const next = stops.find((s) => s.effective_status === 'pending');
        return next ? `${next.type}-${next.id}` : null;
    }, [stops]);

    if (error?.code === 'not_linked') {
        return <Waiting appName={appName} logo={logo} user={error.data?.user} />;
    }

    if (error && !data) {
        return (
            <>
                <Band appName={appName} logo={logo} />
                <main className="c-page">
                    <div className="c-waiting">
                        <h1>{t('We could not load your taxes')}</h1>
                        <Notice type="error">{error.message}</Notice>
                        <div><button type="button" className="c-btn" onClick={() => window.location.reload()}>{t('Try again')}</button></div>
                    </div>
                </main>
            </>
        );
    }

    if (!meta || !data) {
        return <Skeleton appName={appName} logo={logo} />;
    }

    const { customer, summary } = data;
    const name = customer.company || customer.name;

    return (
        <>
            <Band appName={appName} logo={logo} years={meta.years} year={year} onYear={setYear} loading={loading} />
            <main className={`c-page${loading ? ' c-is-loading' : ''}`}>
                <div className="c-who">
                    <h1>{name}</h1>
                    <p>
                        {[customer.customer_type_label, customer.activity, t('tax rate :r in :y', { r: pct(data.tax_rate_for_year), y: data.year })].filter(Boolean).join(' · ')}
                    </p>
                    {customer.startup_rate_until && customer.startup_rate_until >= data.year && (
                        <span className="c-tag">{t('Start-up rate 5% until :y', { y: customer.startup_rate_until })}</span>
                    )}
                </div>

                <NextStop stops={stops} summary={summary} year={data.year} />

                {data.warnings.length > 0 && (
                    <div className="c-notices">
                        {/* Amber is reserved for "due soon"; advisories use the info tint. */}
                        {data.warnings.map((w) => <Notice key={w.message} type={w.level === 'warning' ? 'info' : w.level}>{w.message}</Notice>)}
                    </div>
                )}

                <div className="c-grid">
                    <section aria-labelledby="line-heading">
                        <div className="c-section-head">
                            <h2 id="line-heading">{t('Payments :year', { year: data.year })}</h2>
                            <p>{t('Tap a stop for details')}</p>
                        </div>
                            <Line stops={stops} year={data.year} nextKey={nextKey} onReported={() => getJson(`/api/v1/tax/${year}`).then(setData)} />
                    </section>

                    <div className="c-aside">
                        <section aria-labelledby="year-heading">
                            <div className="c-section-head"><h2 id="year-heading">{t('Your year')}</h2></div>
                            <div style={{ display: 'grid', gap: 20 }}>
                                <Meter data={data} />
                                <Figures data={data} />
                            </div>
                        </section>

                        <section aria-labelledby="docs-heading">
                            <div className="c-section-head"><h2 id="docs-heading">{t('Documents')}</h2></div>
                            <Documents data={data} />
                        </section>

                        <section aria-labelledby="explain-heading">
                            <div className="c-section-head"><h2 id="explain-heading">{t('What each charge is')}</h2></div>
                            <Explanations stops={stops} />
                        </section>
                    </div>
                </div>
            </main>
        </>
    );
}
