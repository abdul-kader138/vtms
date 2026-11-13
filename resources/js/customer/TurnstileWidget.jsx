import { useEffect, useRef } from 'react';

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

let scriptPromise = null;

function loadTurnstileScript() {
    if (window.turnstile) {
        return Promise.resolve();
    }

    if (!scriptPromise) {
        scriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = SCRIPT_SRC;
            script.async = true;
            script.defer = true;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    return scriptPromise;
}

// Renders nothing (and never blocks submission) when no site key is
// configured — mirrors the backend rule in App\Rules\Turnstile, which skips
// verification when TURNSTILE_SECRET_KEY is unset. Keeps local dev
// captcha-free without an `if` at every call site.
export default function TurnstileWidget({ siteKey, onVerify, onExpire }) {
    const containerRef = useRef(null);
    const widgetIdRef = useRef(null);

    useEffect(() => {
        if (!siteKey || !containerRef.current) {
            return;
        }

        let mounted = true;

        loadTurnstileScript().then(() => {
            if (!mounted || !containerRef.current) {
                return;
            }

            widgetIdRef.current = window.turnstile.render(containerRef.current, {
                sitekey: siteKey,
                size: 'flexible',
                theme: 'light',
                callback: onVerify,
                'expired-callback': onExpire,
                'error-callback': onExpire,
            });
        });

        return () => {
            mounted = false;

            if (widgetIdRef.current !== null && window.turnstile) {
                window.turnstile.remove(widgetIdRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteKey]);

    if (!siteKey) {
        return null;
    }

    return <div ref={containerRef} />;
}
