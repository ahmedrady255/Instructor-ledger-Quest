<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\InstructorLedgerEntryResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger entries';

    public function table(Table $table): Table
    {
        return $table
            ->columns(InstructorLedgerEntryResource::ledgerColumns(showInstructor: false))
            ->filters(InstructorLedgerEntryResource::ledgerFilters(showInstructor: false))
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('earned_at', 'desc')
            ->paginationPageOptions([10, 25, 50]);
    }
}
