<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Models\SitePersonnel;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Putting somebody on the register.
 *
 * **A name is the only required field**, and that is §17.5's requirement rather than a convenience: "most attendees on
 * most sites are a subcontractor's labourers", and a form that asked for an employee record would produce a register of
 * the people who happened to be on the payroll.
 *
 * **The induction has an end date.** An induction is not a permanent state — somebody inducted fourteen months ago on a
 * site whose induction lasts a year is not inducted — and a register that could not say so would report full coverage on
 * a site with none.
 */
class SitePersonnelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->disabled(fn (?SitePersonnel $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText('One row per person per job: somebody inducted on the tower is not inducted on the annexe.'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('The only field that is required. Everything else is a bonus.'),

                        TextInput::make('employer')->maxLength(255),
                        TextInput::make('trade')->maxLength(255),
                        TextInput::make('identification')
                            ->label('Identification')
                            ->maxLength(255)
                            ->helperText('Whatever this site checks at the gate: a card number, a CNIC.'),
                        TextInput::make('phone')->tel()->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Currently on site')
                            ->default(true)
                            ->helperText('What makes a lapsed ticket urgent rather than historical.'),
                    ]),

                Section::make('Induction')
                    ->columns(2)
                    ->description('An induction is not a permanent state. Somebody inducted fourteen months ago on a site whose induction lasts a year is not inducted — and a register that could not say so would report full coverage on a site with none.')
                    ->schema([
                        DatePicker::make('inducted_on')->native(false),
                        DatePicker::make('induction_valid_to')
                            ->label('Induction valid to')
                            ->native(false)
                            ->helperText('Leave blank only where this site\'s induction genuinely does not lapse.'),
                        DatePicker::make('first_on_site')->native(false),
                        DatePicker::make('last_on_site')->native(false),
                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }
}
