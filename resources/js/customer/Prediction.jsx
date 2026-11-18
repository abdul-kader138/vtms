import { useEffect, useState } from 'react';
import { deleteJson, getJson, postJson } from './api';
import { locale, t } from './i18n';
import { Notice } from './ui';

// Entirely separate from the real month-by-month figures shown above (see
// TaxDashboard's Figures/Line components) — this never reads or writes
// TaxCalculation/InpsCalculation, only IncomeData (read) and IncomeClosing
// (the close/reopen marker). Closing a month is purely an input signal for
// the year-over-year projection math; it has no effect on real data.

let money;
try {
    money = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR', useGrouping: 'always' });
} catch {
    money = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' });
}

const eur = (value) => (value === null || value === undefined ? '—' : money.format(Number(value)));

function monthName(year, month) {
    return new Date(year, month - 1, 1).toLocaleDateString(locale, { month: 'long' });
}

function MonthRow({ year, entry, closed, busy, onToggle }) {
    return (
        <li className="c-month-row">
            <span className="c-month-name">{monthName(year, entry.month)}</span>
            <span className={`c-tag c-tag--${entry.is_actual ? 'actual' : 'projected'}`}>
                {entry.is_actual ? t('Actual') : t('Projected')}
            </span>
            <span className="c-num c-month-amount">{eur(entry.sales)}</span>
            {entry.is_actual && (
                <button
                    type="button"
                    className="c-btn c-btn--quiet c-btn--small"
                    disabled={busy}
                    onClick={() => onToggle(entry.month, closed)}
                >
                    {closed ? t('Reopen') : t('Close month')}
                </button>
            )}
        </li>
    );
}

export default function Prediction({ year }) {
    const [state, setState] = useState(null);
    const [error, setError] = useState(null);
    const [busyMonth, setBusyMonth] = useState(null);

    useEffect(() => {
        let active = true;
        setState(null);
        setError(null);

        getJson(`/api/v1/tax/${year}/prediction`)
            .then((result) => active && setState(result))
            .catch((err) => active && setError(err));

        return () => {
            active = false;
        };
    }, [year]);

    async function toggleClose(month, isClosed) {
        setBusyMonth(month);
        try {
            const result = isClosed
                ? await deleteJson(`/api/v1/tax/${year}/${month}/close`)
                : await postJson(`/api/v1/tax/${year}/${month}/close`, {});
            setState((previous) => ({ ...previous, closed_months: result.closed_months }));
        } catch {
            // Purely a self-service convenience marker — a failed toggle
            // just leaves the month as it was, nothing to roll back.
        } finally {
            setBusyMonth(null);
        }
    }

    // Secondary, optional section: never block or clutter the real tax
    // dashboard above if this hasn't loaded yet or failed.
    if (error || !state) {
        return null;
    }

    const { closed_months: closedMonths, prediction } = state;

    return (
        <section aria-labelledby="prediction-heading" className="c-prediction">
            <div className="c-section-head">
                <h2 id="prediction-heading">{t('Monthly register & prediction')}</h2>
                <p>{t('Close months as you go; the projection below gets more accurate as the year fills in')}</p>
            </div>

            <Notice type="warning">
                {t('Representative estimate only — not an official figure. It never changes your real tax calculation above.')}
            </Notice>

            {!prediction ? (
                <p className="c-empty">{t('No income recorded yet this year — nothing to project.')}</p>
            ) : (
                <>
                    <dl className="c-figures">
                        <dt>{t('Projected revenue for the year')}</dt><dd>{eur(prediction.predicted_total_sales)}</dd>
                        <dt>{t('Projected taxable income')}</dt><dd>{eur(prediction.predicted_taxable_income)}</dd>
                        <dt className="is-total">{t('Projected flat tax')}</dt><dd className="is-total">{eur(prediction.predicted_tax_due)}</dd>
                        {prediction.predicted_inps && (
                            <><dt>{t('Projected INPS')}</dt><dd>{eur(prediction.predicted_inps.amount_after_discount)}</dd></>
                        )}
                    </dl>

                    <ul className="c-months">
                        {prediction.monthly_projected.map((entry) => (
                            <MonthRow
                                key={entry.month}
                                year={year}
                                entry={entry}
                                closed={!!closedMonths[entry.month]}
                                busy={busyMonth === entry.month}
                                onToggle={toggleClose}
                            />
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
