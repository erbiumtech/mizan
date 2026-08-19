<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Workers\Schemas;

use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\Invoicing\Models\Contact;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * One pair of hands, and the two optional links that say who pays them.
 *
 * **Both links are guarded and both stay null without their module** (§18.1). The employee picker is a plain column
 * with a searched label rather than a Filament relationship, because a relationship needs an `employee()` method on
 * the model and `construction_costing` does not declare `employees` — the licensing claim has to stay true in the
 * import graph, not just in the registry.
 */
class WorkerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Number')
                    ->required()
                    ->maxLength(32)
                    ->helperText('The number the site sheet and the gang leader use. Unique — two people on one number is how a week\'s hours land on the wrong person.'),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Select::make('engagement')
                    ->label('Engaged as')
                    ->options(Worker::ENGAGEMENTS)
                    ->default(Worker::ENGAGEMENT_DIRECT)
                    ->selectablePlaceholder(false)
                    ->live()
                    // The default is `direct` because it is the commonest case on a site and the one the HR register
                    // cannot hold. §7.1: "on a site, most hands are not employees".
                    ->helperText('Most hands on a site are not on the payroll. That is what this register is for.'),

                Select::make('trade_id')
                    ->label('Trade')
                    ->options(fn (): array => Trade::query()->active()->orderBy('sort')->orderBy('code')->get()
                        ->mapWithKeys(fn (Trade $trade): array => [$trade->getKey() => $trade->displayName()])
                        ->all())
                    ->searchable()
                    ->helperText('What decides the rate when nothing more specific is set.'),

                Select::make('employee_id')
                    ->label('Employee record')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search($search))
                    ->getOptionLabelUsing(fn ($value): ?string => EmployeeOptions::labelFor($value))
                    ->visible(fn (callable $get): bool => modules()->enabled('employees')
                        && $get('engagement') === Worker::ENGAGEMENT_EMPLOYEE)
                    ->helperText('Links this person to the payroll, so what the job was charged can be compared with what was actually paid.'),

                Select::make('subcontractor_contact_id')
                    ->label('Supplied by')
                    ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (callable $get): bool => modules()->enabled('invoicing')
                        && $get('engagement') === Worker::ENGAGEMENT_SUPPLIED)
                    // The gang leader is a supplier and a supplier is a Contact, which Invoicing owns.
                    ->helperText('The gang leader or agency who supplies this person, and who is the one that gets paid.'),

                TextInput::make('national_id')
                    ->label('ID number')
                    ->maxLength(64),

                TextInput::make('phone')
                    ->maxLength(32),

                DatePicker::make('started_on')
                    ->label('Started')
                    ->native(false)
                    // Dates rather than the flag alone: "was he on site in March" is asked at the moment somebody
                    // disputes a week's hours, and a flag only ever answers about today.
                    ->helperText('Used to answer "was this person engaged then", which the In-use flag cannot.'),

                DatePicker::make('ended_on')
                    ->label('Left')
                    ->native(false),

                Toggle::make('is_active')
                    ->label('In use')
                    ->default(true)
                    ->helperText('Switching somebody off takes them out of the pickers. There is no delete: a worker with cost against their name is a row somebody will ask about.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
