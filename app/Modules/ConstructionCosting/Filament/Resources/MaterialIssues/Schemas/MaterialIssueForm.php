<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Schemas;

use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\Inventory\Models\StockLocation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The docket header: the store, the date, and the two signatures.
 *
 * **Both names are a worker picker with a free-text field beside it**, and that pairing is deliberate: the storeman
 * knows who collected the material long before that person is on any register, and a docket that cannot be recorded
 * until somebody is registered is a docket that gets written on paper and lost.
 */
class MaterialIssueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The docket')
                    ->columns(2)
                    ->schema([
                        Select::make('stock_location_id')
                            ->label('Out of store')
                            ->options(fn (): array => StockLocation::query()->active()->orderBy('code')->get()
                                ->mapWithKeys(fn (StockLocation $location): array => [
                                    $location->getKey() => $location->displayName(),
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->helperText('The store the material physically came out of.'),

                        DatePicker::make('issued_on')
                            ->label('Issued on')
                            ->native(false)
                            ->required()
                            ->default(now()),

                        TextInput::make('reference')
                            ->label('Paper docket number')
                            ->maxLength(255)
                            ->helperText('What is written on the docket the storeman signed, if it has its own number.'),
                    ]),

                Section::make('The two signatures')
                    ->description('The paper docket has two names on it — who handed the material over and who took it. Both are worth recording: the second is the name somebody asks for when the material is not where it should be.')
                    ->columns(2)
                    ->schema([
                        Select::make('issued_by_worker_id')
                            ->label('Issued by')
                            ->options(fn (): array => static::workers())
                            ->searchable()
                            ->placeholder('Not on the register'),

                        TextInput::make('issued_by_name')
                            ->label('…or a name')
                            ->maxLength(255),

                        Select::make('received_by_worker_id')
                            ->label('Received by')
                            ->options(fn (): array => static::workers())
                            ->searchable()
                            ->placeholder('Not on the register'),

                        TextInput::make('received_by_name')
                            ->label('…or a name')
                            ->maxLength(255)
                            ->helperText('Use this when the person is not on the worker register — a subcontractor\'s ganger, usually.'),
                    ]),

                Section::make('Notes')
                    ->schema([Textarea::make('notes')->rows(2)]),
            ]);
    }

    /** @return array<int, string> */
    private static function workers(): array
    {
        return Worker::query()->active()->orderBy('code')->get()
            ->mapWithKeys(fn (Worker $worker): array => [$worker->getKey() => $worker->displayName()])
            ->all();
    }
}
