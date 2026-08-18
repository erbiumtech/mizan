<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\BackCharges\Schemas;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\BackChargeService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * One back-charge.
 *
 * **There is no notice date on this form**, and that is deliberate: serving notice is a contractual act with a date the
 * subcontract measures, and typing it into the same form that prices the charge makes it look like a field rather than a
 * decision. It is the `notify` action on the register, which is also where the refusals live.
 *
 * **Cost and markup are separate fields** because the markup is the part that gets argued about. A subcontractor will
 * accept an invoice for a skip and contest fifteen per cent on top of it, and a single total gives the argument nowhere
 * to land.
 */
class BackChargeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('contract_id')
                    ->label('Subcontract')
                    // Payable and executed only: there is nothing to deduct from a draft, and against an employer a
                    // charge of this shape is a claim rather than a back-charge.
                    ->options(fn (): array => Contract::query()
                        ->payable()
                        ->whereNot('status', Contract::STATUS_DRAFT)
                        ->with('job')
                        ->get()
                        ->mapWithKeys(fn (Contract $c): array => [
                            $c->getKey() => "{$c->job?->code} · {$c->contract_number} — {$c->title}",
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->helperText('Whose payment this comes off. A back-charge is recovered from the subcontractor who caused it.')
                    // The job follows the contract rather than being asked for twice.
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        $set('job_id', $state ? Contract::find($state)?->job_id : null);
                    }),

                Placeholder::make('reference_preview')
                    ->label('Reference')
                    ->content(fn (callable $get): string => ($contract = $get('contract_id'))
                        ? app(BackChargeService::class)->nextReference(Contract::findOrFail($contract))
                        : 'Assigned when the subcontract is chosen')
                    ->visibleOn('create'),

                Select::make('kind')
                    ->label('What it is for')
                    ->options([
                        'cleanup' => 'Cleanup — the company cleared what they left',
                        'rework' => 'Rework',
                        'ncr_rectification' => 'NCR rectification',
                        'damage' => 'Damage to other works',
                        'materials' => 'Materials supplied on their behalf',
                        'labour' => 'Labour supplied on their behalf',
                        'plant' => 'Plant supplied on their behalf',
                        'attendance' => 'Attendance — craneage, hoisting, unloading',
                        'welfare' => 'Welfare and site facilities',
                        'schedule_recovery' => 'Schedule recovery — acceleration to make up their delay',
                    ])
                    ->searchable()
                    ->required(),

                DatePicker::make('incurred_on')
                    ->label('Incurred')
                    ->default(now())
                    ->helperText('When the company did the work. Notice cannot predate it.'),

                Textarea::make('description')
                    ->label('What happened')
                    ->rows(3)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('This text goes on the notice and onto the certificate as the deduction line. Write it for the subcontractor to read.'),

                TextInput::make('amount')
                    ->label('Cost')
                    ->numeric()
                    ->required()
                    ->helperText('What it cost this company, before any markup.'),

                TextInput::make('markup_percent')
                    ->label('Markup %')
                    ->numeric()
                    ->helperText('For supervision and overhead, where the subcontract allows it. Kept separate because it is the part that gets contested.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
