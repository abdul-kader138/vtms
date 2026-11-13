@php
    $eur = fn ($v) => ($v < 0 ? '− ' : '').'€ '.number_format(abs((float) $v), 2, ',', '.');
    $customers = $this->customerOptions();
    $ready = $this->importableCount();
    $inboxPath = 'storage/app/private/'.\App\Console\Commands\TaxImportInbox::ROOT.'/inbox';
@endphp

<x-filament-panels::page>
    @if ($previews === [])
        <x-filament::section icon="heroicon-o-document-arrow-up" :heading="__('1. Choose files')">
            <form wire:submit="check">
                {{ $this->form }}
                <div style="margin-top:1rem;display:flex;gap:.5rem">
                    <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">{{ __('Check files') }}</x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-document-arrow-down" :heading="__('Sample files')"
                             :description="__('Each sample is exactly the format the import reads, with fictional data. Open one to see the columns, or import it to try the flow.')"
                             collapsible>
            @php
                $samples = [
                    ['invoices', ['csv', 'xlsx'], __('Invoices list'), __('17 columns from "Fatture emesse": number, date, seller VAT, buyer, taxable amount, VAT, SDI id, virtual stamp. Includes a credit note, imported as a negative amount.')],
                    ['cash-register', ['csv', 'xlsx'], __('Cash register (corrispettivi)'), __('9 columns per daily total: send id, device serial, date and time, sales, taxable sales, VAT. Days with no sales are skipped.')],
                    ['e-invoice', ['xml'], __('E-invoice (FatturaPA)'), __('One invoice as the SDI sends it. A ZIP of these files, or signed .xml.p7m files, can be imported the same way.')],
                ];
            @endphp
            <div class="vt-table-wrap">
                <table class="vt-table">
                    <tbody>
                        @foreach ($samples as [$format, $exts, $label, $about])
                            <tr>
                                <td style="width:30%;font-weight:600">{{ $label }}</td>
                                <td class="vt-muted vt-small">{{ $about }}</td>
                                <td style="white-space:nowrap;text-align:right">
                                    @foreach ($exts as $ext)
                                        <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-m-arrow-down-tray"
                                            :href="route('tax.import-sample', ['format' => $format, 'ext' => $ext])">
                                            {{ strtoupper($ext === 'xlsx' ? 'Excel' : $ext) }}
                                        </x-filament::button>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="vt-muted vt-small" style="margin-top:.75rem">{{ __('The samples use VAT number 01234567890. Download them from a customer\'s tax dashboard to get that customer\'s VAT number, so they are matched automatically.') }}</p>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-light-bulb" :heading="__('Where do I get these files?')" collapsible collapsed>
            <ol class="vt-small" style="list-style:decimal;padding-left:1.25rem;display:grid;gap:.375rem">
                <li>{!! __('Sign in to <strong>Fatture e Corrispettivi</strong> (Agenzia delle Entrate) for the customer, or as their delegated intermediary.') !!}</li>
                <li>{!! __('<strong>Invoices:</strong> Consultazione → Fatture elettroniche → Fatture emesse → choose the period → export the list (CSV), or download the invoice files (ZIP of XML/P7M).') !!}</li>
                <li>{!! __('<strong>Cash register:</strong> Corrispettivi → Consultazione dati rilevati → choose the device and period → export (CSV).') !!}</li>
                <li>{{ __('Upload them here. Files already imported are recognised, so uploading the whole year again is safe — only new invoices are added.') }}</li>
            </ol>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-clipboard-document-check" :heading="__('2. Check and import')"
                             :description="__('Nothing has been saved yet. Review each file, fix the customer where needed, then import.')">
            <div class="vt-table-wrap">
                <table class="vt-table">
                    <thead>
                        <tr>
                            <th>{{ __('File') }}</th>
                            <th style="min-width:14rem">{{ __('Customer') }}</th>
                            <th class="vt-num">{{ __('New rows') }}</th>
                            <th class="vt-num">{{ __('Amount') }}</th>
                            <th>{{ __('Period') }}</th>
                            <th>{{ __('Result') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($previews as $i => $p)
                            <tr wire:key="preview-{{ $i }}">
                                <td>
                                    <div style="font-weight:500" class="vt-truncate" title="{{ $p['file'] }}">{{ $p['file'] }}</div>
                                    <div class="vt-muted vt-small">{{ $p['format_label'] ?? __('Unknown format') }}</div>
                                </td>
                                <td>
                                    @if ($p['format'])
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model.live="previews.{{ $i }}.customer_id">
                                                <option value="">{{ __('— choose —') }}</option>
                                                @foreach ($customers as $id => $label)
                                                    <option value="{{ $id }}">{{ $label }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        @if ($p['match'] === 'vat')
                                            <div class="vt-small" style="margin-top:.25rem;color:rgb(var(--success-600))">✓ {{ __('Matched by VAT number') }}</div>
                                        @elseif ($p['match'] === 'device')
                                            <div class="vt-small" style="margin-top:.25rem;color:rgb(var(--success-600))">✓ {{ __('Matched by cash register') }}</div>
                                        @endif
                                    @else
                                        <span class="vt-muted">—</span>
                                    @endif
                                </td>
                                <td class="vt-num">
                                    {{ $p['new'] }}
                                    @if ($p['duplicates'])<div class="vt-muted vt-small">{{ __(':n already imported', ['n' => $p['duplicates']]) }}</div>@endif
                                    @if ($p['credit_notes'])<div class="vt-muted vt-small">{{ __(':n credit notes', ['n' => $p['credit_notes']]) }}</div>@endif
                                </td>
                                <td class="vt-num">{{ $p['new'] ? $eur($p['total']) : '—' }}</td>
                                <td class="vt-small">
                                    @if ($p['period_from'])
                                        {{ \Illuminate\Support\Carbon::parse($p['period_from'])->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($p['period_to'])->format('d/m/Y') }}
                                    @else
                                        <span class="vt-muted">—</span>
                                    @endif
                                </td>
                                <td style="min-width:16rem">
                                    @if ($p['error'])
                                        <div class="vt-alert vt-alert--danger" style="padding:.5rem .75rem">
                                            <x-filament::icon icon="heroicon-m-x-circle" />
                                            <span>{{ $p['error'] }}</span>
                                        </div>
                                    @elseif ($p['new'] === 0)
                                        <div style="display:flex"><x-filament::badge color="gray">{{ __('Nothing new') }}</x-filament::badge></div>
                                    @else
                                        <div style="display:flex"><x-filament::badge color="success" icon="heroicon-m-check">{{ __('Ready') }}</x-filament::badge></div>
                                    @endif
                                    @foreach ($p['warnings'] as $warning)
                                        <div class="vt-small" style="margin-top:.375rem;color:rgb(var(--warning-600))">⚠ {{ $warning }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                <x-filament::button wire:click="import" icon="heroicon-m-arrow-down-tray" :disabled="$ready === 0" wire:loading.attr="disabled" wire:target="import">
                    {{ trans_choice('{0} Nothing to import|{1} Import 1 file|[2,*] Import :count files', $ready, ['count' => $ready]) }}
                </x-filament::button>
                <x-filament::button color="gray" wire:click="cancel" icon="heroicon-m-x-mark">{{ __('Start over') }}</x-filament::button>
                <span class="vt-muted vt-small" wire:loading wire:target="import">{{ __('Importing and recalculating…') }}</span>
            </div>
        </x-filament::section>
    @endif

    <div class="vt-alert vt-alert--info">
        <x-filament::icon icon="heroicon-o-inbox-arrow-down" />
        <div>
            <strong>{{ __('Automatic import') }}</strong> —
            {{ __('Files saved in the inbox folder are imported every 15 minutes, and you get a notification. Put a file in a sub-folder named after the customer id or VAT number to skip detection.') }}
            <div style="margin-top:.25rem"><span class="vt-code">{{ $inboxPath }}</span></div>
        </div>
    </div>

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament-panels::page>
