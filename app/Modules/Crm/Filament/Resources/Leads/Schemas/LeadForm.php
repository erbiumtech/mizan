<?php

namespace App\Modules\Crm\Filament\Resources\Leads\Schemas;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Who')
                ->description('A lead is an organisation with a person attached. Either may be blank — a name from a trade show is still a lead — but not both.')
                ->schema([
                    TextInput::make('company_name')
                        ->label('Company')
                        ->maxLength(255)
                        ->requiredWithout('person_name'),

                    TextInput::make('person_name')
                        ->label('Contact person')
                        ->maxLength(255)
                        ->requiredWithout('company_name'),

                    TextInput::make('title')
                        ->label('Their job title')
                        ->maxLength(255)
                        ->placeholder('Head of Finance'),

                    TextInput::make('city')->maxLength(255),
                ])
                ->columns(2),

            Section::make('How to reach them')
                ->schema([
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('phone')->tel()->maxLength(50),
                    // Separate from phone because in this market the number somebody
                    // answers on WhatsApp is often not the one on their card.
                    TextInput::make('whatsapp')->label('WhatsApp')->tel()->maxLength(50),
                ])
                ->columns(3),

            Section::make('Where it came from, and whose it is')
                ->schema([
                    Select::make('lead_source_id')
                        ->label('Source')
                        ->options(fn (): array => LeadSource::query()
                            ->active()
                            ->orderBy('sort')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->helperText('Worth filling in: win rate by source is the report this table exists for.'),

                    // Guarded. Without `employees` there is no employee to own a lead,
                    // so the field is absent rather than empty and `created_by`
                    // answers "whose lead is this" instead.
                    Select::make('owner_employee_id')
                        ->label('Owner')
                        ->relationship('owner', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                            ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                            $search,
                            EmployeeOptions::accessibleScope(),
                        ))
                        ->preload()
                        ->visible(fn (): bool => modules()->enabled('employees'))
                        ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id'))
                        ->helperText('Who is working it. This is also what decides who can see it.'),

                    Select::make('status')
                        ->options([
                            Lead::STATUS_NEW => 'New — nobody has touched it',
                            Lead::STATUS_WORKING => 'Working it',
                            Lead::STATUS_QUALIFIED => 'Qualified — worth pursuing',
                        ])
                        ->default(Lead::STATUS_NEW)
                        ->required()
                        // Converted and lost are absent on purpose: both are reached
                        // through their own action, which records when it happened and
                        // why. A dropdown would let somebody set "lost" with no reason
                        // and no date, which is exactly what win/loss cannot use.
                        ->helperText('Converting a lead and marking it lost are actions on the list, not choices here — each records when and why.'),

                    Select::make('rating')
                        ->options([
                            Lead::RATING_HOT => 'Hot',
                            Lead::RATING_WARM => 'Warm',
                            Lead::RATING_COLD => 'Cold',
                        ])
                        ->helperText('Your own read on it. Nothing computes this, and nothing should.'),
                ])
                ->columns(2),

            Section::make('What it might be worth')
                ->schema([
                    TextInput::make('estimated_value')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('A guess, and treated as one. The forecast that adds these up properly comes with deals in a later phase.'),

                    TextInput::make('currency_code')
                        ->label('Currency')
                        ->maxLength(3)
                        ->placeholder('PKR')
                        ->helperText('Leave blank for the company\'s own currency.'),

                    Textarea::make('notes')->rows(3)->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
