<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only audit trail over the activity_log table (populated by
 * LogsActivity on the core models plus the auth/access-control listeners in
 * App\Listeners). No create/edit/delete — an audit log that can be edited
 * from the same panel it audits isn't trustworthy.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('Audit Log');
    }

    public static function getModelLabel(): string
    {
        return __('Audit Log Entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audit Log');
    }

    protected static ?string $modelLabel = 'Audit Log Entry';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function table(Table $table): Table
    {
        return $table
            // `causer.name` below is one query per row without this —
            // every page of the audit log otherwise does N+1 lookups on
            // whichever staff member triggered each entry.
            ->modifyQueryUsing(fn ($query) => $query->with('causer'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime('d M Y H:i:s')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('log_name')
                    ->label(__('Area'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('Activity'))
                    ->formatStateUsing(fn (?string $state) => $state ? __(ucfirst($state)) : '—')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('causer.name')
                    ->label(__('By'))
                    ->default(__('System'))
                    ->searchable(),

                TextColumn::make('subject_type')
                    ->label(__('On'))
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('event')
                    ->label(__('Event'))
                    ->formatStateUsing(fn (?string $state) => $state ? __(ucfirst($state)) : '—')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('Area'))
                    ->options(fn () => Activity::query()->distinct()->pluck('log_name', 'log_name')->filter()->all()),

                SelectFilter::make('event')
                    ->label(__('Event'))
                    ->options([
                        'created' => __('Created'),
                        'updated' => __('Updated'),
                        'deleted' => __('Deleted'),
                    ]),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')
                            ->label(__('From'))
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d')
                            ->placeholder('dd/mm/yyyy'),
                        DatePicker::make('until')
                            ->label(__('To'))
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->format('Y-m-d')
                            ->placeholder('dd/mm/yyyy'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    // viewAny/view authorization comes from App\Policies\ActivityPolicy
    // (view_any_activity::log / view_activity::log), generated by
    // shield:generate and explicitly registered in AppServiceProvider since
    // Activity lives outside App\Models and Laravel can't auto-discover its
    // policy. create/edit/delete are hard-disabled below regardless of
    // permissions — no page or action ever exercises them, by design.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
