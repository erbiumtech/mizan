<?php

namespace App\Filament\Resources\EmployeeChangeRequests\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class EmployeeChangeRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        // Records are immutable in Nova (authorizedToCreate/Update = false); form retained for parity only.
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'id')
                    ->searchable()
                    ->preload()
                    ->disabled(),

                Select::make('requested_by')
                    ->label('Requested By')
                    ->relationship('requester', 'name')
                    ->searchable()
                    ->preload()
                    ->disabled(),

                KeyValue::make('requested_changes')
                    ->label('Requested Changes')
                    ->keyLabel('Field')
                    ->valueLabel('New Value')
                    ->disabled(),

                Select::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->disabled(),
            ]);
    }
}
