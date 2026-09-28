<?php

namespace App\Filament\Resources\InstructorLedgerEntryResource\Pages;

use App\Filament\Resources\InstructorLedgerEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorLedgerEntries extends ListRecords
{
    protected static string $resource = InstructorLedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
