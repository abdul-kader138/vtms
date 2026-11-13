<x-filament::dropdown placement="bottom-end">
    <x-slot name="trigger">
        <button type="button" class="flex items-center gap-1.5 rounded-lg p-2 text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/10" aria-label="{{ __('Language') }}" title="{{ __('Language') }}">
            @php
                $languages = [
                    'en' => ['name' => 'English', 'flag' => '🇬🇧'],
                    'it' => ['name' => 'Italiano', 'flag' => '🇮🇹'],
                    'bn' => ['name' => 'বাংলা', 'flag' => '🇧🇩'],
                ];
                $selectedLocale = auth()->user()?->locale ?? app()->getLocale();
                $selectedLanguage = $languages[$selectedLocale] ?? $languages['en'];
            @endphp
            <span class="text-base leading-none" role="img" aria-label="{{ $selectedLanguage['name'] }}">{{ $selectedLanguage['flag'] }}</span>
            <span class="hidden text-xs font-medium sm:inline">{{ strtoupper(auth()->user()?->locale ?? app()->getLocale()) }}</span>
        </button>
    </x-slot>

    <x-filament::dropdown.list>
        @foreach($languages as $code => $language)
            <form method="POST" action="{{ route('locale.update') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $code }}">
                <button type="submit" class="flex w-full items-center justify-between gap-5 px-3 py-2 text-start text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                    <span class="flex items-center gap-2">
                        <span class="text-base leading-none" role="img" aria-label="{{ $language['name'] }}">{{ $language['flag'] }}</span>
                        <span>{{ __($language['name']) }}</span>
                    </span>
                    @if($selectedLocale === $code)
                        <x-filament::icon icon="heroicon-m-check" class="h-4 w-4 text-primary-600" />
                    @endif
                </button>
            </form>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
