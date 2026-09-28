<?php

namespace App\Filament\Resources;

use App\Domain\Ledger\LedgerEntryType;
use App\Filament\Resources\InstructorLedgerEntryResource\Pages\ListInstructorLedgerEntries;
use App\Models\InstructorLedgerEntry;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InstructorLedgerEntryResource extends Resource
{
    protected static ?string $model = InstructorLedgerEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Ledger entries';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::ledgerColumns())
            ->filters(static::ledgerFilters())
            ->actions([])
            ->bulkActions([])
            ->defaultSort('earned_at', 'desc')
            ->paginationPageOptions([10, 25, 50]);
    }

    public static function ledgerColumns(bool $showInstructor = true): array
    {
        return [
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('instructor.name')
                ->label('Instructor')
                ->searchable()
                ->visible($showInstructor),
            Tables\Columns\TextColumn::make('type')->badge(),
            Tables\Columns\TextColumn::make('amount_minor')
                ->label('Amount')
                ->formatStateUsing(fn (int $state, InstructorLedgerEntry $record): string => number_format($state / 100, 2, '.', ',').' '.$record->currency),
            Tables\Columns\TextColumn::make('currency'),
            Tables\Columns\TextColumn::make('payment.provider_reference')->label('Payment'),
            Tables\Columns\TextColumn::make('refund_id')->label('Refund')->placeholder('—'),
            Tables\Columns\TextColumn::make('schedule_item_id')->label('Schedule')->placeholder('—'),
            Tables\Columns\TextColumn::make('earned_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('source_key')->searchable(),
        ];
    }

    public static function ledgerFilters(bool $showInstructor = true): array
    {
        return [
            SelectFilter::make('instructor')
                ->relationship('instructor', 'name')
                ->searchable()
                ->preload()
                ->visible($showInstructor),
            SelectFilter::make('type')->options(collect(LedgerEntryType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->value])),
            SelectFilter::make('currency')->options(fn () => InstructorLedgerEntry::query()->distinct()->pluck('currency', 'currency')),
            Filter::make('earned_at')->form([
                DatePicker::make('from'),
                DatePicker::make('until'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('earned_at', '>=', $date))
                ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('earned_at', '<=', $date))),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListInstructorLedgerEntries::route('/')];
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
}
