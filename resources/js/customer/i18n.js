// Server-rendered strings for the current locale, handed over by
// resources/views/customer/auth.blade.php. English pages pass an empty
// catalog, so every key falls back to itself — the same contract as
// Laravel's __() with JSON translation files.
const root = document.getElementById('customer-auth-root');
const catalog = JSON.parse(root?.dataset.translations || '{}');

export function t(key, replacements = {}) {
    return Object.entries(replacements).reduce(
        (text, [name, value]) => text.replaceAll(`:${name}`, value),
        catalog[key] ?? key,
    );
}

export const locale = document.documentElement.lang || 'en';
