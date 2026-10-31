<?php

namespace App\Modules\Employees\Filament\Resources\Employees\RelationManagers;

use App\Filament\Support\PendingFirst;
use App\Modules\Employees\Models\EmployeeChangeRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Change Requests';

    public function table(Table $table): Table
    {
        // Change requests are created through the Employee model's saving
        // hook only — read-only here (no create/edit/delete/associate).
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable(),

                TextColumn::make('requester.name')
                    ->label(__('Requested By')),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Requested At'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort(PendingFirst::sort(EmployeeChangeRequest::class, 'created_at'));
    }
}
