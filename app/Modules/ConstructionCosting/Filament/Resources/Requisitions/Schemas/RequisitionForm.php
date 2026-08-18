<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\Requisition;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/**
 * A requisition's header — deliberately short, because site staff fill it in.
 *
 * **There is no money on this form and no cost code.** The estimate lives on the lines and is only ever an estimate;
 * the cost code is the buyer's decision at order time. Asking site for a code would teach people to pick whichever
 * one lets the form save, and that looks like data afterwards.
 *
 * **Required by is a date rather than a priority flag**, because "urgent" means whatever the person ticking it wants
 * it to mean and a date is something a buyer can plan against.
 */
class RequisitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->live()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    // Moving a request to another job would take its order lines with it, and they carry that job.
                    ->disabled(fn (?Requisition $record): bool => $record !== null)
                    ->dehydrated(),

                DatePicker::make('required_by')
                    ->label('Needed on site by')
                    ->helperText('What orders the buyer\'s queue. A date rather than a priority, because a date can be planned against.'),

                Select::make('wbs_node_id')
                    ->label('WBS element')
                    ->options(fn (callable $get): array => WbsNode::query()
                        ->where('job_id', $get('job_id'))
                        ->orderBy('path')->get()
                        ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Whole job'),

                Select::make('location_id')
                    ->label('Deliver to')
                    ->options(fn (callable $get): array => Location::query()
                        ->where('job_id', $get('job_id'))
                        ->orderBy('path')->get()
                        ->mapWithKeys(fn (Location $location): array => [$location->getKey() => "{$location->code} — {$location->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Site address')
                    ->helperText('Which part of the site it is wanted at.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('Anything the buyer needs to know — a brand that was specified, a delivery window.'),
            ]);
    }
}
