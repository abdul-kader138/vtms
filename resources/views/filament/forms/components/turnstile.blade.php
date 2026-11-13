@php
    $statePath = $getStatePath();
    $siteKey = config('services.turnstile.site_key');
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($siteKey)
        <script>
            // Tokens are single-use, so the form asks for a fresh one after any
            // rejected submit (see HasTurnstileField::resetTurnstile()).
            window.appTurnstile = window.appTurnstile || {
                load() {
                    if (window.turnstile || document.querySelector('script[data-app-turnstile]')) {
                        return;
                    }
                    const script = document.createElement('script');
                    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
                    script.async = true;
                    script.dataset.appTurnstile = 'true';
                    document.head.appendChild(script);
                },
                mount(root, siteKey) {
                    const input = root.querySelector('input');
                    const setToken = (token) => {
                        input.value = token;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    };
                    this.load();

                    const interval = setInterval(() => {
                        if (!root.isConnected) {
                            clearInterval(interval);
                            return;
                        }
                        if (!window.turnstile) {
                            return;
                        }
                        clearInterval(interval);

                        const widgetId = window.turnstile.render(root.querySelector('[data-turnstile-widget]'), {
                            sitekey: siteKey,
                            size: 'flexible',
                            theme: 'light',
                            callback: setToken,
                            'expired-callback': () => setToken(''),
                            'error-callback': () => setToken(''),
                        });
                        const reset = () => {
                            if (!root.isConnected) {
                                window.removeEventListener('turnstile-reset', reset);
                                return;
                            }
                            setToken('');
                            window.turnstile.reset(widgetId);
                        };
                        window.addEventListener('turnstile-reset', reset);
                    }, 100);
                },
            };
        </script>

        {{-- wire:ignore keeps Livewire off the widget Cloudflare injects. x-init
             (unlike a plain script) also runs when the field returns to the page,
             such as going back from the two-factor step. --}}
        <div wire:ignore x-data x-init="window.appTurnstile.mount($el, @js($siteKey))">
            <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}" />
            <div data-turnstile-widget></div>
        </div>
    @endif
</x-dynamic-component>
