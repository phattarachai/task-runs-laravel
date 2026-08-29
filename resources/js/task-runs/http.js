/**
 * Minimal fetch helpers for the tasks panel — no axios, no route helpers. Writes carry the
 * XSRF-TOKEN cookie Laravel sets (accepted on the X-XSRF-TOKEN header); reads still send
 * X-Requested-With so an unauthenticated poll gets a 401/419 instead of a login-page redirect
 * that response.json() would choke on.
 */
function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function getJson(url) {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error('The request failed.');
    }

    return response.json();
}

export async function postJson(url, body = {}) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));

        throw new Error(payload.message ?? 'The request failed.');
    }

    return response.json();
}
