<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Itps\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionQhse\Models\Itp;
use App\Support\TenantDb;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The plan's cover sheet.
 *
 * **The status is not on this form.** A plan is drafted, then *issued*, then *approved*, and each of those is an action
 * with its own rule and its own permission — issuing refuses a hold point that names nobody, and approving is answerable
 * to a certification body. A status somebody could type would let a plan be approved without either check.
 */
class ItpForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The plan')
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
                            ->disabled(fn (?Itp $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('contract_id')
                            ->label('Under contract')
                            ->options(fn (callable $get): array => static::contracts($get('job_id')))
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                            ->placeholder('None'),

                        TextInput::make('reference')
                            ->required()
                            ->maxLength(255)
                            ->helperText('ITP-CIV-001. Quoted in correspondence and printed on every check sheet raised against it.'),

                        TextInput::make('title')->required()->maxLength(255),

                        TextInput::make('discipline')
                            ->maxLength(255)
                            ->helperText('Civil, structural, mechanical — whatever this project calls them.'),

                        Select::make('wbs_node_id')
                            ->label('WBS node')
                            ->options(fn (callable $get): array => static::wbsNodes($get('job_id')))
                            ->searchable(),

                        Textarea::make('scope')->rows(3)->columnSpanFull(),

                        TextInput::make('revision')
                            ->maxLength(255)
                            ->helperText('Left blank, issuing sets it to A. Revising supersedes this plan and starts the next letter.'),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return array<int, string> */
    private static function wbsNodes(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return WbsNode::query()
            ->where('job_id', $jobId)
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
            ->all();
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
}
