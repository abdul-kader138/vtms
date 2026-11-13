<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Filament\Resources\InpsContributionRuleResource\Pages;
use App\Models\InpsContributionRule;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Yearly INPS IVS parameters for Artigiani / Commercianti (law rules). */
class InpsContributionRuleResource extends Resource
{
    protected static ?string $model = InpsContributionRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('INPS contribution rules');
    }

    public static function getModelLabel(): string
    {
        return __('INPS contribution rule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('INPS contribution rules');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('year')->label(__('Year'))->numeric()->minValue(2000)->maxValue(2100)->required(),
            Select::make('customer_type')->label(__('Customer type'))->native(false)->required()
                ->options([CustomerType::Artigiani->value => CustomerType::Artigiani->label(), CustomerType::Commercianti->value => CustomerType::Commercianti->label()])
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('year', $get('year'))),
            TextInput::make('minimum_income')->label(__('Minimum income (minimale)'))->numeric()->prefix('€')->required()->live(onBlur: true),
            TextInput::make('rate')->label(__('Rate'))->numeric()->suffix('%')->required()->live(onBlur: true),
            TextInput::make('maternity_fee')->label(__('Maternity fee (per year)'))->numeric()->prefix('€')->default(7.44)->required()->live(onBlur: true),
            Placeholder::make('fixed')->label(__('Fixed contributions (full year)'))
                ->content(fn (Get $get) => '€ '.number_format((float) $get('minimum_income') * (float) $get('rate') / 100 + (float) $get('maternity_fee'), 2, ',', '.')),
            TextInput::make('threshold')->label(__('Threshold for the extra point'))->numeric()->prefix('€')->required(),
            TextInput::make('rate_above_threshold')->label(__('Rate above the threshold'))->numeric()->suffix('%')->required(),
            TextInput::make('maximum_income')->label(__('Maximum income (massimale)'))->numeric()->prefix('€')->required(),
            TextInput::make('source')->label(__('Source'))->placeholder('INPS circ. …/'.now()->year)->maxLength(255),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->label(__('Year'))->sortable(),
                TextColumn::make('customer_type')->label(__('Type'))->badge()->formatStateUsing(fn (CustomerType $state) => $state->label()),
                TextColumn::make('minimum_income')->label(__('Minimale'))->money('EUR'),
                TextColumn::make('fixed')->label(__('Fixed (year)'))->state(fn (InpsContributionRule $r) => $r->fixedAmount())->money('EUR'),
                TextColumn::make('rate')->label(__('Rate'))->suffix('%')
                    ->description(fn (InpsContributionRule $r) => __('above :t: :r%', ['t' => '€'.number_format((float) $r->threshold, 0, ',', '.'), 'r' => $r->rate_above_threshold])),
                TextColumn::make('maximum_income')->label(__('Massimale'))->money('EUR'),
                TextColumn::make('source')->label(__('Source'))->wrap()
                    ->color(fn (InpsContributionRule $r) => $r->isVerified() ? null : 'danger')
                    ->icon(fn (InpsContributionRule $r) => $r->isVerified() ? null : 'heroicon-m-exclamation-triangle'),
            ])
            ->defaultSort('year', 'desc')
            ->filters([SelectFilter::make('customer_type')->label(__('Type'))->options([CustomerType::Artigiani->value => CustomerType::Artigiani->label(), CustomerType::Commercianti->value => CustomerType::Commercianti->label()])])
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageInpsContributionRules::route('/')];
    }
}
