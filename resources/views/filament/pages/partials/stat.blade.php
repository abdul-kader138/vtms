@props(['label', 'value', 'isCurrency' => true])

<div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
    <div class="text-lg font-semibold">
        @if ($isCurrency && is_numeric($value))
            € {{ number_format((float) $value, 2) }}
        @else
            {{ $value ?? '—' }}
        @endif
    </div>
</div>
