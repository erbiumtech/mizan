<?php

namespace App\Modules\ConstructionField\Filament\Resources\PunchLists\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionField\Models\PunchList;
use App\Support\TenantDb;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Opening a list — §16.4.
 *
 * **The kind is the field that matters, and `internal` is why.** An internal quality sweep is the contractor's own, made
 * before anybody else is invited to look; a client list is the employer's. Merging them would put the contractor's own
 * findings into a document the employer can quote, which is the fastest way to teach a site team to stop writing
 * anything down.
 */
class PunchListForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The list')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabled(fn (?PunchList $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('contract_id')
                            ->label('Under contract')
                            ->options(fn (callable $get): array => static::contracts($get('job_id')))
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                            ->placeholder('None'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('What somebody has to recognise at handover. "Level 4 pre-handover walk, 12 August" beats "List 3".'),

                        Select::make('kind')
                            ->options(PunchList::KINDS)
                            ->default(PunchList::KIND_PRE_HANDOVER)
                            ->required()
                            ->helperText('An internal sweep is yours. A client list is the employer\'s and can be quoted back at you — keep them apart.'),

                        Select::make('location_id')
                            ->label('Where')
                            ->options(fn (callable $get): array => static::locations($get('job_id')))
                            ->searchable()
                            ->helperText('The area this walk covered, from the job\'s location tree. Items carry their own place as well.'),

                        DatePicker::make('opened_on')
                            ->label('Walked on')
                            ->native(false)
                            ->default(now())
                            ->required(),

                        DatePicker::make('target_completion_date')
                            ->label('To be cleared by')
                            ->native(false),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The job's contracts, read out of the table.
     *
     * `construction_contracts` is guarded — this module requires only `construction` — so the picker asks the query
     * builder and never names `Contract`.
     *
     * @return array<int, string>
     */
    private static function contracts(int|string|null $jobId): array
    {
        if ($jobId === null || ! modules()->enabled('construction_contracts')) {
            return [];
        }

        return TenantDb::table('construction_contracts')
            ->where('job_id', $jobId)
            ->orderBy('contract_number')
            ->get(['id', 'contract_number', 'title'])
            ->mapWithKeys(fn ($row): array => [$row->id => "{$row->contract_number} — {$row->title}"])
            ->all();
    }

    /** @return array<int, string> */
    private static function locations(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return Location::query()
            ->where('job_id', $jobId)
            ->orderByRaw('LENGTH(path)')
            ->orderBy('sort_order')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Location $location): array => [$location->getKey() => $location->fullName()])
            ->all();
    }
}
