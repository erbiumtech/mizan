<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers;

use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogManpower;
use App\Modules\ConstructionField\Services\DailyLogService;
use App\Modules\Invoicing\Models\Contact;
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
 * Who was on site — §16.1, and §17.6's denominator.
 *
 * **This tab is why a safety rate can exist at all.** §17.6 calls exposure hours "the denominator nobody has": an
 * incident rate is incidents per so many hours worked, and without a manpower return there is no hours figure, so the
 * safety page has to refuse to print a rate rather than print a flattering one.
 *
 * The trade picker reads `construction_trades` **without naming the `Trade` class** — that table belongs to
 * `construction_costing` and this module requires only `construction` (§18). Where the cost module is absent the picker
 * is gone and the free-text label carries it: a diary must never be unfillable because of a licence.
 */
class ManpowerRelationManager extends RelationManager
{
    protected static string $relationship = 'manpower';

    protected static ?string $title = 'Manpower';

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
                Select::make('trade_id')
                    ->label('Trade')
                    ->options(fn (): array => static::trades())
                    ->searchable()
                    ->visible(fn (): bool => modules()->enabled('construction_costing'))
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        // The label is snapshotted from the register, so the diary still reads correctly if the trade is
                        // renamed or the cost module is later switched off. A diary is evidence; it cannot depend on a
                        // lookup that may move.
                        $set('trade_label', $state ? (static::trades()[$state] ?? null) : null);
                    }),

                TextInput::make('trade_label')
                    ->label('Trade, in words')
                    ->maxLength(255)
                    ->helperText('Filled in from the trade register where you have one. Type it where you do not.'),

                Select::make('contact_id')
                    ->label('Supplied by')
                    ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (): bool => modules()->enabled('invoicing')),

                TextInput::make('company_label')
                    ->label('Company, in words')
                    ->maxLength(255),

                TextInput::make('headcount')->numeric()->default(0),
                TextInput::make('hours')->label('Hours each')->numeric()->default(0),
                TextInput::make('overtime_hours')->label('Overtime hours')->numeric()->default(0),
                TextInput::make('notes')->maxLength(255)->columnSpanFull(),
            ]);
    }

    /** @return array<int, string> */
    private static function trades(): array
    {
        if (! modules()->enabled('construction_costing')) {
            return [];
        }

        return DB::table('construction_trades')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($row): array => [$row->id => "{$row->code} — {$row->name}"])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('trade_label')
            ->columns([
                TextColumn::make('trade_label')
                    ->label('Trade')
                    ->placeholder('—'),

                TextColumn::make('company')
                    ->label('Supplied by')
                    ->getStateUsing(fn (DailyLogManpower $record): ?string => $record->companyName())
                    ->placeholder('own labour'),

                TextColumn::make('headcount')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('On site')),

                TextColumn::make('hours')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Hours')),

                TextColumn::make('overtime_hours')
                    ->label('Overtime')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Overtime')),

                TextColumn::make('notes')->wrap()->placeholder('—')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false)
                    ->using(fn (array $data): Model => app(DailyLogService::class)->addManpower($this->log(), $data)),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->log()) ?? false),
            ])
            ->emptyStateHeading('Nobody recorded')
            ->emptyStateDescription('Man-hours here are what a safety rate is divided by, and what dayworks are priced from. A day with nobody on it is worth recording as such.');
    }
}
