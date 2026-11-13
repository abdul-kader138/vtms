{{-- Shared <head> for the customer side. $title: page title (already translated). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0057a8">
<title>{{ $title }} · {{ \App\Models\Setting::get('app_name', 'VTMS') }}</title>
@if ($favicon = \App\Providers\Filament\AdminPanelProvider::resolveFaviconUrl())
    <link rel="icon" href="{{ $favicon }}">
@endif
@vite(array_merge(['resources/css/customer.css'], $scripts ?? []))
