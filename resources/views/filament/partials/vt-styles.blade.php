{{-- Shared styles for the custom tax views (dashboard, widgets, payment
     calendar). The panel has no compiled Tailwind theme, so utilities used
     only in these views would never be generated; these use Filament's own
     color variables so they follow the panel color and dark mode. --}}
<style>
    .vt-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .75rem; }
    .vt-mb { margin-bottom: 1rem; }
    .vt-muted { color: rgb(var(--gray-500)); }
    .vt-small { font-size: .75rem; }
    .vt-num { font-variant-numeric: tabular-nums; white-space: nowrap; text-align: right; }

    .vt-stat { border: 1px solid rgba(var(--gray-500), .18); border-radius: .75rem; padding: .875rem 1rem; background: rgba(var(--gray-500), .03); }
    .vt-stat-label { font-size: .75rem; color: rgb(var(--gray-500)); display: flex; align-items: center; gap: .375rem; }
    .vt-stat-value { font-size: 1.25rem; font-weight: 600; margin-top: .25rem; font-variant-numeric: tabular-nums; }
    .vt-stat--danger { border-color: rgba(var(--danger-500), .45); background: rgba(var(--danger-500), .07); }
    .vt-stat--danger .vt-stat-value { color: rgb(var(--danger-600)); }
    .vt-stat--warning .vt-stat-value { color: rgb(var(--warning-600)); }
    .vt-stat--success .vt-stat-value { color: rgb(var(--success-600)); }
    .vt-stat--primary .vt-stat-value { color: rgb(var(--primary-600)); }
    .dark .vt-stat--danger .vt-stat-value { color: rgb(var(--danger-400)); }
    .dark .vt-stat--warning .vt-stat-value { color: rgb(var(--warning-400)); }
    .dark .vt-stat--success .vt-stat-value { color: rgb(var(--success-400)); }
    .dark .vt-stat--primary .vt-stat-value { color: rgb(var(--primary-400)); }

    .vt-alert { display: flex; gap: .625rem; align-items: flex-start; border: 1px solid; border-radius: .75rem; padding: .875rem 1rem; font-size: .875rem; }
    .vt-alert svg { width: 1.25rem; height: 1.25rem; flex: none; }
    .vt-alert--danger { border-color: rgba(var(--danger-500), .5); background: rgba(var(--danger-500), .08); color: rgb(var(--danger-700)); }
    .vt-alert--warning { border-color: rgba(var(--warning-500), .5); background: rgba(var(--warning-500), .08); color: rgb(var(--warning-700)); }
    .vt-alert--info { border-color: rgba(var(--info-500), .5); background: rgba(var(--info-500), .08); color: rgb(var(--info-700)); }
    .dark .vt-alert--danger { color: rgb(var(--danger-300)); }
    .dark .vt-alert--warning { color: rgb(var(--warning-300)); }
    .dark .vt-alert--info { color: rgb(var(--info-300)); }

    .vt-months { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem; font-size: .75rem; }
    .vt-month { border-radius: .25rem; padding: .125rem .5rem; background: rgba(var(--gray-500), .12); color: rgb(var(--gray-500)); }
    .vt-month--on { background: rgba(var(--success-500), .18); color: rgb(var(--success-700)); font-weight: 600; }
    .dark .vt-month--on { color: rgb(var(--success-400)); }

    .vt-table-wrap { overflow-x: auto; }
    .vt-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
    .vt-table th { text-align: left; font-size: .75rem; font-weight: 500; color: rgb(var(--gray-500)); padding: .375rem .75rem .375rem 0; white-space: nowrap; }
    .vt-table td { padding: .5rem .75rem .5rem 0; border-top: 1px solid rgba(var(--gray-500), .15); vertical-align: middle; }
    .vt-table tr.vt-row--overdue td { background: rgba(var(--danger-500), .04); }

    .vt-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; padding: .0625rem .375rem; border-radius: .25rem; background: rgba(var(--gray-500), .12); white-space: nowrap; }
    .vt-iconbtn { display: inline-flex; align-items: center; justify-content: center; width: 1.875rem; height: 1.875rem; border-radius: .5rem; color: rgb(var(--gray-500)); }
    .vt-iconbtn:hover { background: rgba(var(--gray-500), .12); color: rgb(var(--primary-600)); }
    .vt-iconbtn svg { width: 1.125rem; height: 1.125rem; }
    .vt-actions { display: flex; justify-content: flex-end; gap: .125rem; }

    .vt-profile { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .75rem 1.5rem; }
    .vt-profile dt { font-size: .75rem; color: rgb(var(--gray-500)); }
    .vt-profile dd { font-weight: 500; margin-top: .125rem; }

    .vt-list { display: flex; flex-direction: column; }
    .vt-list-item { display: flex; align-items: center; gap: .75rem; padding: .625rem 0; border-top: 1px solid rgba(var(--gray-500), .15); }
    .vt-list-item:first-child { border-top: 0; }
    .vt-date { flex: none; width: 3rem; text-align: center; border-radius: .5rem; padding: .25rem 0; background: rgba(var(--gray-500), .1); line-height: 1.1; }
    .vt-date b { display: block; font-size: 1rem; }
    .vt-date span { font-size: .625rem; text-transform: uppercase; color: rgb(var(--gray-500)); }
    .vt-date--overdue { background: rgba(var(--danger-500), .14); color: rgb(var(--danger-600)); }
    .vt-grow { flex: 1; min-width: 0; }
    .vt-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .vt-bar { position: relative; height: .5rem; border-radius: 999px; background: rgba(var(--gray-500), .15); overflow: hidden; }
    .vt-bar > i { position: absolute; inset: 0 auto 0 0; border-radius: 999px; }
    .vt-bar > i.vt-bar-projected { background: rgba(var(--warning-500), .35); }
    .vt-bar > i.vt-bar-actual { background: rgb(var(--primary-500)); }
    .vt-bar > i.vt-bar-actual.over { background: rgb(var(--danger-500)); }

    .vt-filters { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; }
    .vt-filters label { display: flex; flex-direction: column; gap: .25rem; font-size: .75rem; color: rgb(var(--gray-500)); }
    .vt-group-heading { font-size: .8125rem; font-weight: 600; padding: 1rem 0 .25rem; color: rgb(var(--gray-600)); }
    .dark .vt-group-heading { color: rgb(var(--gray-300)); }
</style>
