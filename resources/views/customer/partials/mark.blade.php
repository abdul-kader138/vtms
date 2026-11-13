{{-- Brand mark on the green band: the uploaded logo, else the name's initial in a square. --}}
@php
    $appName = \App\Models\Setting::get('app_name', 'VTMS');
    $logo = \App\Providers\Filament\AdminPanelProvider::resolveBrandLogoUrl();
@endphp
<a class="c-mark" href="{{ route('customer.tax') }}">
    @if ($logo)
        <img src="{{ $logo }}" alt="">
    @else
        <span class="c-mark-square" aria-hidden="true">{{ mb_strtoupper(mb_substr($appName, 0, 1)) }}</span>
    @endif
    <span>{{ $appName }}</span>
</a>
