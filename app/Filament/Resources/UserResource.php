<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\ListInstructors;
use App\Filament\Resources\UserResource\Pages\ViewInstructorFinancialSummary;
use App\Filament\Resources\UserResource\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\PayoutsRelationManager;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Instructor finances';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name')->label('Instructor'),
            TextEntry::make('email'),
            RepeatableEntry::make('balanceSnapshots')
                ->label('Balances by currency')
                ->schema([
                    TextEntry::make('currency'),
                    self::moneyEntry('earned_minor', 'Earned'),
                    self::moneyEntry('adjusted_minor', 'Adjustments'),
                    self::moneyEntry('paid_minor', 'Paid'),
                    self::moneyEntry('reserved_minor', 'Reserved'),
                    self::moneyEntry('outstanding_minor', 'Outstanding'),
                    TextEntry::make('as_of')->dateTime(),
                ])->columns(7)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [LedgerEntriesRelationManager::class, PayoutsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstructors::route('/'),
            'view' => ViewInstructorFinancialSummary::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->email_verified_at !== null;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    private static function moneyEntry(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2, '.', ','));
    }
}
