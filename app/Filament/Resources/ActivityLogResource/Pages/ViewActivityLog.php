<?php

namespace App\Filament\Resources\ActivityLogResource\Pages;

use App\Filament\Resources\ActivityLogResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class ViewActivityLog extends ViewRecord
{
    protected static string $resource = ActivityLogResource::class;

    // 'causer.name' in the infolist would otherwise lazy-load the causer.
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('causer');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make(__('Activity'))
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label(__('When'))
                        ->dateTime('d M Y H:i:s'),

                    TextEntry::make('log_name')
                        ->label(__('Area'))
                        ->badge(),

                    TextEntry::make('description')
                        ->label(__('Activity'))
                        ->formatStateUsing(fn (?string $state) => $state ? __(ucfirst($state)) : '—')
                        ->columnSpanFull(),

                    TextEntry::make('causer.name')
                        ->label(__('Performed by'))
                        ->default(__('System')),

                    TextEntry::make('subject_type')
                        ->label(__('On'))
                        ->formatStateUsing(fn (Activity $record) => $record->subject_type
                            ? class_basename($record->subject_type).' #'.$record->subject_id
                            : '—'),

                    TextEntry::make('properties.ip')
                        ->label(__('IP address'))
                        ->default('—'),
                ]),

            Section::make(__('Changed values'))
                ->visible(fn (Activity $record) => filled($record->properties?->get('attributes')))
                ->schema([
                    KeyValueEntry::make('properties.old')
                        ->label(__('Before')),

                    KeyValueEntry::make('properties.attributes')
                        ->label(__('After')),
                ])
                ->columns(2),

            Section::make(__('Other properties'))
                ->visible(fn (Activity $record) => filled(
                    $record->properties?->except(['attributes', 'old', 'ip'])->all()
                ))
                ->schema([
                    KeyValueEntry::make('properties')
                        ->label('')
                        ->state(fn (Activity $record) => $record->properties?->except(['attributes', 'old', 'ip'])->all() ?? []),
                ]),
        ]);
    }
}
