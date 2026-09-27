<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Domain\Payouts\PayoutStatus;
use App\Models\Payout;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('Payout')->searchable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state, Payout $record): string => number_format($state / 100, 2, '.', ',').' '.$record->currency),
                Tables\Columns\TextColumn::make('currency'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(PayoutStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->value])),
                SelectFilter::make('currency')->options(fn () => Payout::query()->distinct()->pluck('currency', 'currency')),
                Filter::make('created_at')->form([
                    DatePicker::make('from'),
                    DatePicker::make('until'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'], fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                    ->when($data['until'], fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50]);
    }
}
