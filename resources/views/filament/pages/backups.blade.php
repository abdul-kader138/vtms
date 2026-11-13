@php
    $destinations = $this->getDestinations();
    $health = $this->health($destinations);
    $size = fn (float $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : number_format($bytes / 1024, 0, ',', '.').' KB';
@endphp

<x-filament-panels::page>
    @if ($health === 'ok')
        <div class="vt-alert vt-alert--info">
            <x-filament::icon icon="heroicon-o-shield-check" />
            <span>{{ __('Backups are up to date.') }}</span>
        </div>
    @else
        <div class="vt-alert vt-alert--danger">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" />
            <span>{{ $health === 'missing' ? __('There is no backup yet. Click "Back up now", and make sure the scheduler runs (php artisan schedule:run every minute).') : __('The newest backup is more than a day old. Check that the scheduler is running.') }}</span>
        </div>
    @endif

    @unless ($this->offsiteConfigured())
        <div class="vt-alert vt-alert--warning">
            <x-filament::icon icon="heroicon-o-cloud-arrow-up" />
            <span>{{ __('Backups are only stored on this server. Add an off-site disk (for example S3) to BACKUP_DISKS so a backup survives losing the server.') }}</span>
        </div>
    @endunless

    @foreach ($destinations as $d)
        <x-filament::section :heading="__('Disk: :disk', ['disk' => $d['disk']])" :description="$d['reachable'] ? __(':n backups · :size used', ['n' => $d['backups']->count(), 'size' => $size($d['used'])]) : null">
            @if (! $d['reachable'])
                <div class="vt-alert vt-alert--danger"><x-filament::icon icon="heroicon-m-x-circle" /><span>{{ __('Not reachable: :error', ['error' => $d['error'] ?? '—']) }}</span></div>
            @elseif ($d['backups']->isEmpty())
                <p class="vt-muted">{{ __('No backups on this disk yet.') }}</p>
            @else
                <div class="vt-table-wrap">
                    <table class="vt-table">
                        <thead>
                            <tr>
                                <th>{{ __('Created') }}</th>
                                <th>{{ __('File') }}</th>
                                <th class="vt-num">{{ __('Size') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['backups'] as $backup)
                                <tr>
                                    <td>{{ $backup->date()->timezone(config('app.timezone'))->format('d/m/Y H:i') }} <span class="vt-muted vt-small">· {{ $backup->date()->diffForHumans() }}</span></td>
                                    <td><span class="vt-code">{{ basename($backup->path()) }}</span></td>
                                    <td class="vt-num">{{ $size($backup->sizeInBytes()) }}</td>
                                    <td>
                                        <div class="vt-actions">
                                            <button type="button" class="vt-iconbtn" title="{{ __('Download') }}" aria-label="{{ __('Download') }}"
                                                    wire:click="download('{{ $d['disk'] }}', '{{ $backup->path() }}')">
                                                <x-filament::icon icon="heroicon-o-arrow-down-tray" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endforeach

    <x-filament::section :heading="__('Restoring a backup')" icon="heroicon-o-lifebuoy" collapsible collapsed>
        <ol class="vt-small" style="list-style:decimal;padding-left:1.25rem;display:grid;gap:.375rem">
            <li>{{ __('Download the backup and unzip it. db-dumps/ holds the database, private/ and public/ the uploaded files.') }}</li>
            <li>{!! __('Database: <span class="vt-code">gunzip -c mysql-vtms-….sql.gz | mysql -u root -p vtms</span> — this replaces the current data, so take a fresh backup first.') !!}</li>
            <li>{!! __('Files: copy private/ and public/ back into <span class="vt-code">storage/app/</span>.') !!}</li>
        </ol>
    </x-filament::section>
</x-filament-panels::page>
