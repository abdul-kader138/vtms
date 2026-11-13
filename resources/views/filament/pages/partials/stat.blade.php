@props(['label', 'value', 'isCurrency' => true, 'tone' => null, 'hint' => null])

<div @class(['vt-stat', 'vt-stat--'.$tone => $tone])>
    <div class="vt-stat-label">{{ $label }}</div>
    <div class="vt-stat-value">
        @if ($isCurrency && is_numeric($value))
            {{ (float) $value < 0 ? '− ' : '' }}€ {{ number_format(abs((float) $value), 2, ',', '.') }}
        @else
            {{ $value ?? '—' }}
        @endif
    </div>
    @if ($hint)
        <div class="vt-muted vt-small" style="margin-top:.125rem">{{ $hint }}</div>
    @endif
</div>
