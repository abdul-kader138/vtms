@php
    $translationFile = lang_path(app()->getLocale().'.json');
    $translations = app()->getLocale() !== 'en' && is_file($translationFile)
        ? json_decode(file_get_contents($translationFile), true)
        : new \stdClass;
    $logo = \App\Providers\Filament\AdminPanelProvider::resolveBrandLogoUrl();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('customer.partials.head', ['title' => __('My taxes'), 'scripts' => ['resources/js/customer-tax.jsx']])
</head>
<body class="c-body">
    <div
        id="customer-tax-root"
        data-app-name="{{ \App\Models\Setting::get('app_name', 'VTMS') }}"
        data-logo="{{ $logo }}"
        data-view="{{ $view ?? 'tax' }}"
        data-translations="{{ json_encode($translations) }}"
    ></div>
</body>
</html>
