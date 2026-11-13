<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super-admin view of the nightly backups (spatie/laravel-backup): are they
 * recent, where are they, download one, or take one now.
 */
class Backups extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.backups';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Backups');
    }

    public function getTitle(): string
    {
        return __('Backups');
    }

    public function getSubheading(): ?string
    {
        return __('The database and uploaded files are backed up every night at 01:30, and before every migration.');
    }

    /**
     * @return Collection<int, array{disk: string, reachable: bool, error: ?string, backups: Collection<int, Backup>, used: float}>
     */
    public function getDestinations(): Collection
    {
        return collect(config('backup.backup.destination.disks'))->map(function (string $disk) {
            $destination = BackupDestination::create($disk, config('backup.backup.name'));
            $reachable = $destination->isReachable();

            return [
                'disk' => $disk,
                'reachable' => $reachable,
                'error' => $reachable ? null : $destination->connectionError()?->getMessage(),
                'backups' => $reachable ? $destination->backups() : collect(),
                'used' => $reachable ? $destination->usedStorage() : 0,
            ];
        });
    }

    /** ok | stale | missing — the same rule backup:monitor mails about. */
    public function health(Collection $destinations): string
    {
        $newest = $destinations->flatMap(fn ($d) => $d['backups'])->sortByDesc(fn (Backup $b) => $b->date())->first();

        return match (true) {
            $newest === null => 'missing',
            $newest->date()->lt(now()->subDay()) => 'stale',
            default => 'ok',
        };
    }

    public function offsiteConfigured(): bool
    {
        return collect(config('backup.backup.destination.disks'))
            ->contains(fn ($disk) => config("filesystems.disks.{$disk}.driver") !== 'local');
    }

    public function download(string $disk, string $path): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($disk, config('backup.backup.destination.disks'), true), 404);

        $backup = BackupDestination::create($disk, config('backup.backup.name'))->backups()
            ->first(fn (Backup $b) => $b->path() === $path);
        abort_if($backup === null, 404);

        return response()->streamDownload(function () use ($backup) {
            fpassthru($backup->stream());
        }, basename($path), ['Content-Type' => 'application/zip']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backupNow')
                ->label(__('Back up now'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription(__('Backs up the database and uploaded files now. This can take a minute.'))
                ->action(function () {
                    $status = Artisan::call('backup:run', ['--disable-notifications' => true]);

                    $status === 0
                        ? Notification::make()->success()->title(__('Backup completed'))->send()
                        : Notification::make()->danger()->title(__('Backup failed'))->body(mb_substr(trim(Artisan::output()), -500))->persistent()->send();
                }),
        ];
    }
}
