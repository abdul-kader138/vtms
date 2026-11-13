<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Staff account management — creating/editing users and assigning Spatie
 * roles. Account provisioning (including who can be granted super_admin)
 * stays super_admin-only.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    protected static ?int $navigationSort = 10;

    // A real column — 'name' is a computed accessor (first_name + last_name),
    // and global search below runs `where($attribute, 'like', ...)` directly
    // against the database, which would break on a non-column attribute.
    protected static ?string $recordTitleAttribute = 'first_name';

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'email'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('Account'))
                ->schema([
                    FileUpload::make('avatar')
                        ->label(__('Profile picture'))
                        ->avatar()
                        ->imageEditor()
                        ->circleCropper()
                        ->disk('public')
                        ->directory('avatars')
                        ->visibility('public')
                        ->imageResizeTargetWidth(256)
                        ->imageResizeTargetHeight(256)
                        ->imageResizeMode('cover')
                        ->maxSize(5120)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->helperText(__('JPG, PNG or WEBP. Maximum size: 5 MB.'))
                        ->columnSpanFull(),

                    TextInput::make('first_name')
                        ->label(__('First name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('last_name')
                        ->label(__('Last name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label(__('Email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email', ignoreRecord: true),

                    Select::make('locale')
                        ->label(__('Language'))
                        ->options(['en' => __('English'), 'it' => __('Italiano'), 'bn' => __('বাংলা')])
                        ->placeholder(__('Use system default'))
                        ->native(false)
                        ->helperText(__('Leave blank to follow the system default language.')),

                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->rule(Password::default())
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                        ->helperText(__('At least 8 characters, with uppercase, lowercase, and a number. Leave blank to keep the current password.')),

                    TextInput::make('password_confirmation')
                        ->label(__('Confirm Password'))
                        ->password()
                        ->revealable()
                        ->dehydrated(false)
                        ->same('password')
                        ->required(fn (string $operation) => $operation === 'create'),

                    // An account a super admin provisions here does not go
                    // through the customer email-verification flow: left on
                    // (the default), the user can sign in immediately; turned
                    // off, `email_verified_at` stays null and the customer
                    // API refuses the login until they verify (see
                    // routes/api.php's 'verified' group). Only meaningful at
                    // creation time — an existing user's verification state
                    // is managed by verifying, not by this form.
                    Toggle::make('email_verified_at')
                        ->label(__('Email verified'))
                        ->helperText(__('On: the user can sign in right away. Off: they must verify their email first.'))
                        ->default(true)
                        ->visible(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (string $operation) => $operation === 'create')
                        ->dehydrateStateUsing(fn ($state) => $state ? now() : null),
                ])->columns(2),

            Section::make(__('Roles'))
                ->description(__('What this user can access in the admin panel.'))
                ->schema([
                    Select::make('roles')
                        ->label(__('Roles'))
                        ->multiple()
                        ->relationship('roles', 'name')
                        ->options(fn () => Role::pluck('name', 'id'))
                        ->preload()
                        ->helperText(__('What this user can access in the admin panel.'))
                        // Goes through syncRoles() rather than Filament's default
                        // pivot ->sync() so RoleAttached/RoleDetached fire and
                        // the change lands in the audit log (see
                        // App\Listeners\LogPermissionActivity).
                        // $state is an array of role IDs as strings (all form
                        // state round-trips through HTTP as strings), and
                        // syncRoles() treats a numeric string as a role *name*
                        // lookup rather than an ID lookup — resolve to Role
                        // models first so it doesn't misinterpret them.
                        ->saveRelationshipsUsing(fn ($record, $state) => $record->syncRoles(Role::findMany($state))),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // roles.permissions + permissions, not just roles — isStaff()
            // calls getAllPermissions(), which walks both relations; without
            // eager-loading both, strict mode throws on the lazy load.
            ->modifyQueryUsing(fn ($query) => $query->with(['roles.permissions', 'permissions', 'userSession']))
            ->columns([
                ImageColumn::make('avatar')
                    ->label(__('Profile picture'))
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    // The avatar column stores a path, so checking every
                    // file on every row would add one storage request per
                    // user. The database is the source of truth here and
                    // the placeholder handles users without an avatar.
                    ->checkFileExistence(false)
                    ->extraImgAttributes(['loading' => 'lazy', 'decoding' => 'async'])
                    ->defaultImageUrl(asset('images/user-placeholder.svg')),

                TextColumn::make('first_name')
                    ->label(__('First name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->label(__('Last name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles.name')->label(__('Roles'))
                    ->badge()
                    ->separator(',')
                    ->color('primary'),

                TextColumn::make('email_verified_at')
                    ->label(__('Verified'))
                    ->dateTime()
                    ->placeholder(__('Not verified'))
                    ->toggleable(),

                // Staff are never device-locked (see DeviceLockService), so
                // this is always blank for them rather than a confusing
                // "not locked" for an account the feature doesn't apply to.
                TextColumn::make('device_lock_status')
                    ->label(__('Device'))
                    ->state(function (User $record): ?string {
                        if ($record->isStaff()) {
                            return null;
                        }

                        return filled($record->userSession?->device_fingerprint)
                            ? __('Locked')
                            : __('Not locked');
                    })
                    ->badge()
                    ->color(fn (?string $state) => $state === 'Locked' ? 'warning' : 'gray')
                    ->placeholder('—')
                    ->tooltip(function (User $record): ?string {
                        $session = $record->userSession;

                        if (! $session?->last_seen_at) {
                            return null;
                        }

                        $where = $session->ip_address ?? __('an unknown address');

                        if (filled($session->country)) {
                            $where .= " ({$session->country})";
                        }

                        return __('Last seen :time from :where', ['time' => $session->last_seen_at->diffForHumans(), 'where' => $where]);
                    }),

                TextColumn::make('created_at')
                    ->label(__('Joined'))
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->preload(),
                TernaryFilter::make('device_locked')
                    ->label(__('Device lock'))
                    ->placeholder(__('All users'))
                    ->trueLabel(__('Locked to a device'))
                    ->falseLabel(__('Not locked'))
                    ->queries(
                        true: fn ($query) => $query->whereHas('userSession', fn ($q) => $q->whereNotNull('device_fingerprint')),
                        false: fn ($query) => $query->whereDoesntHave('userSession', fn ($q) => $q->whereNotNull('device_fingerprint')),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('resetDevice')
                    ->label(__('Reset device'))
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('warning')
                    ->visible(fn (User $record) => ! $record->isStaff() && filled($record->userSession?->device_fingerprint))
                    ->requiresConfirmation()
                    ->modalHeading(__('Reset locked device?'))
                    ->modalDescription(__('This user will be able to sign in from a new device. Their current session, if any, ends immediately.'))
                    ->action(function (User $record): void {
                        $record->userSession?->delete();

                        Notification::make()
                            ->title(__('Device reset'))
                            ->body(__(':name can now sign in from a new device.', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
