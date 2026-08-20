<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers;

use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogPlant;
use App\Modules\ConstructionField\Services\DailyLogService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What plant did — §16.1.
 *
 * **Three hour columns, because they are three arguments.** §16.1: "idle against working is what a standing-time claim is
 * made of and one combined hours column loses it entirely." Breakdown is separate again, because it is usually the
 * contractor's own risk where idle is not — and a claim that mixes them invites the whole thing to be refused.
 *
 * The *Why it stood* field earns its place: idle hours with no reason are the ones nobody can recover next month, and
 * the table flags them.
 */
class PlantRelationManager extends RelationManager
{
    protected static string $relationship = 'plant';

    protected static ?string $title = 'Plant';

    private function log(): DailyLog
    {
        /** @var DailyLog $log */
        $log = $this->getOwnerRecord();

        return $log;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('plant_item_id')
                    ->label('Machine')
                    ->options(fn (): array => static::plant())
                    ->searchable()
                    ->visible(fn (): bool => modules()->enabled('construction_costing'))
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        // Snapshotted for the same reason as the trade: a diary cannot depend on a lookup that may be
                        // renamed, or on a module that may later be switched off.
                        $set('plant_label', $state ? (static::plant()[$state] ?? null) : null);
                    }),

                TextInput::make('plant_label')
                    ->label('Machine, in words')
                    ->maxLength(255)
                    ->helperText('Filled in from the fleet register where you have one.'),

                TextInput::make('working_hours')->label('Working')->numeric()->default(0),
                TextInput::make('idle_hours')->label('Idle')->numeric()->default(0)
                    ->helperText('On site, available, not working. This is the standing-time figure.'),
                TextInput::make('breakdown_hours')->label('Broken down')->numeric()->default(0)
                    ->helperText('Kept apart from idle: breakdown is usually your own risk and idle usually is not.'),

                TextInput::make('idle_reason')
                    ->label('Why it stood')
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Waiting on another trade, no operator, no access, weather. Idle hours with no reason are the ones nobody can recover.'),

                TextInput::make('notes')->maxLength(255)->columnSpanFull(),
            ]);
    }

    /** @return array<int, string> */
    private static function plant(): array
    {
        if (! modules()->enabled('construction_costing')) {
            return [];
        }

        return DB::table('construction_plant_items')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($row): array => [$row->id => "{$row->code} — {$row->name}"])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('plant_label')
            ->columns([
                TextColumn::make('plant_label')->label('Machine')->placeholder('—'),

                TextColumn::make('working_hours')
                    ->label('Working')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Working')),

                TextColumn::make('idle_hours')
                    ->label('Idle')
                    ->alignEnd()
                    ->description(fn (DailyLogPlant $record): ?string => $record->isUnexplainedIdle()
                        // Named rather than left blank: this is the hour nobody recovers.
                        ? 'no reason given'
                        : $record->idle_reason)
                    ->summarize(Sum::make()->label('Idle')),

                TextColumn::make('breakdown_hours')
                    ->label('Broken')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Broken')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false)
                    ->using(fn (array $data): Model => app(DailyLogService::class)->addPlant($this->log(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),
            ])
            ->emptyStateHeading('No plant recorded')
            ->emptyStateDescription('Working, idle and broken down are three different arguments. Recording them as one number loses the standing-time claim entirely.');
    }
}
