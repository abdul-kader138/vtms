import { locale, t } from './i18n';

function readCookie(name) {
    const match = document.cookie.match(new RegExp('(^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[2]) : null;
}

// Sanctum's SPA cookie auth needs a fresh XSRF-TOKEN cookie before any
// state-changing request — this hits Sanctum's built-in /sanctum/csrf-cookie
// route (no controller of ours involved) to set it.
async function ensureCsrfCookie() {
    if (readCookie('XSRF-TOKEN')) {
        return;
    }

    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
}

export async function postJson(path, body) {
    await ensureCsrfCookie();

    const response = await fetch(path, {
        method: 'POST',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'Accept-Language': locale,
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
        },
        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || t('Request failed.'));
        error.status = response.status;
        error.errors = data.errors || {};
        throw error;
    }

    return data;
}

async function sendMultipart(path, method, form) {
    await ensureCsrfCookie();

    const response = await fetch(path, {
        method,
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Accept-Language': locale,
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
        },
        body: form,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || t('Request failed.'));
        error.status = response.status;
        error.errors = data.errors || {};
        throw error;
    }

    return data;
}

export function postMultipart(path, form) {
    return sendMultipart(path, 'POST', form);
}

export async function putJson(path, body) {
    await ensureCsrfCookie();

    const response = await fetch(path, {
        method: 'PUT',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'Accept-Language': locale,
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
        },
        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || t('Request failed.'));
        error.status = response.status;
        error.errors = data.errors || {};
        throw error;
    }

    return data;
}

export async function getJson(path) {
    const response = await fetch(path, {
        credentials: 'include',
        headers: { Accept: 'application/json', 'Accept-Language': locale },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message || t('Request failed.'));
        error.status = response.status;
        error.code = data.code;
        error.data = data;
        throw error;
    }

    return data;
}
