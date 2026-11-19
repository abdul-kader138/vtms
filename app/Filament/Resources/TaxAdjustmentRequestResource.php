<?php

namespace App\Filament\Resources;

use App\Enums\TaxAdjustmentRequestStatus;
use App\Filament\Resources\TaxAdjustmentRequestResource\Pages;
use App\Models\TaxAdjustmentRequest;
use App\Services\Tax\TaxCalculationOrchestrator;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Firm-wide review queue for manual tax-figure overrides a staff member
 * without finalize_tax_calculation submitted via
 * CustomerTaxDashboard::recalculateAction() — see
 * App\Exceptions\Tax\ManualOverrideApprovalRequiredException. No
 * create/edit: a request's payload is exactly what was submitted in the
 * review modal (see TaxCalculationOrchestrator::approveRequest()).
 */
class TaxAdjustmentRequestResource extends Resource
{
    protected static ?string $model = TaxAdjustmentRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('Tax Adjustment Requests');
    }

    public static function getModelLabel(): string
    {
        return __('Tax Adjustment Request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Tax Adjustment Requests');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', TaxAdjustmentRequestStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'requestedBy', 'reviewedBy']))
            ->columns([
                TextColumn::make('customer.name')->label(__('Customer'))->searchable()->sortable(),
                TextColumn::make('tax_year')->label(__('Year'))->sortable(),
                TextColumn::make('changed_fields')
                    ->label(__('Changed'))
                    ->state(fn (TaxAdjustmentRequest $record) => collect($record->payload)
                        ->flatMap(fn ($fields, $section) => collect($fields)->except('adjustment_reason')->keys()->map(fn ($field) => "{$section}.{$field}"))
                        ->implode(', '))
                    ->wrap(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->state(fn (TaxAdjustmentRequest $record) => collect($record->payload)->map(fn ($fields) => $fields['adjustment_reason'] ?? null)->filter()->implode(' / '))
                    ->wrap()
                    ->limit(60),
                TextColumn::make('requestedBy.name')->label(__('Requested by')),
                TextColumn::make('requested_at')->label(__('Requested at'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (TaxAdjustmentRequestStatus $state) => match ($state) {
                        TaxAdjustmentRequestStatus::Pending => 'warning',
                        TaxAdjustmentRequestStatus::Approved => 'success',
                        TaxAdjustmentRequestStatus::Rejected => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('reviewedBy.name')->label(__('Reviewed by'))->placeholder('—'),
            ])
            ->defaultSort('requested_at', 'desc')
            ->actions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TaxAdjustmentRequest $record) => $record->status === TaxAdjustmentRequestStatus::Pending && (Auth::user()?->can('finalize_tax_calculation') ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('This applies the requested figures exactly as submitted, under your own identity.'))
                    ->modalContent(fn (TaxAdjustmentRequest $record) => static::diffContent($record))
                    ->action(function (TaxAdjustmentRequest $record) {
                        try {
                            $result = app(TaxCalculationOrchestrator::class)->approveRequest($record);

                            Notification::make()->success()->title(__('Approved'))->body(__('The adjustment has been applied.'))->send();

                            if ($result['variance_warnings'] !== []) {
                                Notification::make()->warning()->persistent()
                                    ->title(__('Large manual adjustment(s) — please double-check'))
                                    ->body(implode("\n", $result['variance_warnings']))
                                    ->send();
                            }
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('Could not approve'))->body($e->getMessage())->send();
                        }
                    }),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (TaxAdjustmentRequest $record) => $record->status === TaxAdjustmentRequestStatus::Pending && (Auth::user()?->can('finalize_tax_calculation') ?? false))
                    ->form([
                        Textarea::make('review_note')->label(__('Reason for rejecting'))->required()->rows(2),
                    ])
                    ->action(function (TaxAdjustmentRequest $record, array $data) {
                        $record->update([
                            'status' => TaxAdjustmentRequestStatus::Rejected,
                            'reviewed_by' => Auth::id(),
                            'reviewed_at' => now(),
                            'review_note' => $data['review_note'],
                        ]);

                        Notification::make()->success()->title(__('Rejected'))->send();
                    }),
            ]);
    }

    /** Computed-now vs requested, for the approver to see before confirming. */
    private static function diffContent(TaxAdjustmentRequest $record): HtmlString
    {
        $preview = app(TaxCalculationOrchestrator::class)->preview($record->customer, $record->tax_year);
        $lines = [];

        foreach ($record->payload as $section => $fields) {
            foreach ($fields as $field => $value) {
                if ($field === 'adjustment_reason' || ! is_numeric($value)) {
                    continue;
                }

                $computed = $preview[$section][$field] ?? null;

                $lines[] = __(':field: computed € :computed → requested € :requested', [
                    'field' => "{$section}.{$field}",
                    'computed' => $computed !== null ? number_format((float) $computed, 2, ',', '.') : '—',
                    'requested' => number_format((float) $value, 2, ',', '.'),
                ]);
            }
        }

        return new HtmlString('<ul style="list-style:disc;padding-left:1.25rem">'
            .collect($lines)->map(fn ($line) => '<li>'.e($line).'</li>')->implode('')
            .'</ul>');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxAdjustmentRequests::route('/'),
        ];
    }
}
