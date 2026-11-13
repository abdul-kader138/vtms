@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field"></x-dynamic-component>

{{--
    A plain <script> tag here is not reliably executed: on Filament's
    initial Livewire-rendered payload it can land in the DOM via a path the
    browser never "parses" as a fresh <script> (so it silently never runs,
    with no console error). @script/@endscript is Livewire's own mechanism
    for JS that must run once the component is actually hydrated — on first
    load included — and it hands us $wire directly instead of the
    hidden-input/dispatchEvent workaround.
--}}
@script
<script>
    (function () {
        // Not a real device ID — browsers never expose one (no site can
        // read a MAC address). This is a best-effort composite of
        // browser/device signals, stable across page loads on the same
        // browser but not cryptographically unique: clearing site data, a
        // private window, or switching browsers on the same physical
        // machine can all produce a different value. That's the known
        // trade-off of fingerprinting over a persistent cookie.
        function collectSignals() {
            var signals = [
                navigator.userAgent || '',
                navigator.language || '',
                (navigator.languages || []).join(','),
                screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
                (Intl.DateTimeFormat().resolvedOptions().timeZone) || '',
                navigator.hardwareConcurrency || '',
                navigator.platform || '',
                navigator.deviceMemory || '',
            ];

            try {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillText('device-fingerprint', 2, 2);
                signals.push(canvas.toDataURL());
            } catch (e) {
                // Canvas blocked (privacy extension, old browser) — the
                // remaining signals still form a usable fingerprint.
            }

            return signals.join('||');
        }

        // cyrb53 — a small, fast, non-cryptographic string hash. We only
        // need "very likely different for a different device", not
        // cryptographic collision resistance.
        function hash(str) {
            var h1 = 0xdeadbeef, h2 = 0x41c6ce57;
            for (var i = 0; i < str.length; i++) {
                var ch = str.charCodeAt(i);
                h1 = Math.imul(h1 ^ ch, 2654435761);
                h2 = Math.imul(h2 ^ ch, 1597334677);
            }
            h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
            h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
            return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(36);
        }

        $wire.set(@js($statePath), hash(collectSignals()), false);
    })();
</script>
@endscript
