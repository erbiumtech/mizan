<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\Schemas;

use App\Support\EmployeeOptions;
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
                    ->label(__('Employee'))
                    ->relationship('employee', 'employee_id', fn ($query) => $query->with('user'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                    ->searchable()
                    // Search the user's name as well as the employee code — the label
                    // shows both, but Filament searches only the title attribute.
                    ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search($search))
                    ->preload()
                    ->disabled(),

                Select::make('requested_by')
                    ->label(__('Requested By'))
                    ->relationship('requester', 'name')
                    ->searchable()
                    ->preload()
                    ->disabled(),

                KeyValue::make('requested_changes')
                    ->label(__('Requested Changes'))
                    ->keyLabel('Field')
                    ->valueLabel('New Value')
                    // A key-value field holds strings; an address arrives as an array of parts.
                    ->formatStateUsing(fn (?array $state): array => array_map(fn (mixed $value): string => \App\Modules\Employees\Models\EmployeeChangeRequest::displayValue($value), $state ?? []))
                    ->disabled(),

                Select::make('status')
                    ->label(__('Status'))
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->disabled(),
            ]);
    }
}
