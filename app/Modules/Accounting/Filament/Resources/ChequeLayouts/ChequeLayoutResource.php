<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeLayouts;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Accounting\Filament\Resources\ChequeLayouts\Pages\CreateChequeLayout;
use App\Modules\Accounting\Filament\Resources\ChequeLayouts\Pages\EditChequeLayout;
use App\Modules\Accounting\Filament\Resources\ChequeLayouts\Pages\ListChequeLayouts;
use App\Modules\Accounting\Models\ChequeLayout;
use App\Modules\Accounting\Services\ChequePrinter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The layout designer — docs/saas-launch-plan.md §8.6. Positions in millimetres
 * from the top-left of the leaf, and a test print that puts each field's name
 * where the field would go, to hold against a real cheque.
 */
class ChequeLayoutResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ChequeLayout::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewfinderCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Cheque Layouts';

    protected static ?int $navigationSort = 27;

    public static function form(Schema $schema): Schema
    {
        $defaults = ChequeLayout::defaultFields();

        $fields = [];
        foreach (ChequeLayout::FIELDS as $name) {
            $fields[] = Fieldset::make(ucfirst(str_replace('_', ' ', $name)))
                ->columns(4)
                ->schema([
                    TextInput::make("fields.{$name}.x")->label(__('Left (mm)'))->numeric()->step(0.5)->required()->default($defaults[$name]['x']),
                    TextInput::make("fields.{$name}.y")->label(__('Top (mm)'))->numeric()->step(0.5)->required()->default($defaults[$name]['y']),
                    TextInput::make("fields.{$name}.w")->label(__('Width (mm)'))->numeric()->step(0.5)->required()->default($defaults[$name]['w']),
                    TextInput::make("fields.{$name}.font")->label(__('Font (pt)'))->numeric()->step(0.5)->required()->default($defaults[$name]['font']),
                ]);
        }

        return $schema->components([
            Section::make('Paper')
                ->columns(3)
                ->schema([
                    TextInput::make('name')->required()->maxLength(80),
                    Select::make('company_bank_account_id')
                        ->label(__('Bank account'))
                        ->relationship('companyBankAccount', 'title')
                        ->preload()
                        ->searchable()
                        ->unique(ignoreRecord: true)
                        ->helperText('Leave empty for the layout every account without its own uses.'),
                    TextInput::make('page_width_mm')->label(__('Width (mm)'))->numeric()->step(0.5)->required()->default(180),
                    TextInput::make('page_height_mm')->label(__('Height (mm)'))->numeric()->step(0.5)->required()->default(80),
                ]),
            Section::make('Fields')
                ->description('Millimetres from the top-left corner of the leaf. Print a test, lay it over a real cheque, and correct the numbers.')
                ->schema($fields),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                TextColumn::make('companyBankAccount.title')->label(__('Bank account'))->placeholder('Default'),
                TextColumn::make('size')->label(__('Paper'))->state(fn (ChequeLayout $record): string => $record->page_width_mm.' × '.$record->page_height_mm.' mm'),
            ])
            ->recordActions([
                static::testPrintAction(),
                EditAction::make(),
                DeleteAction::make(),
                ReplicateRecordAction::make(),
            ]);
    }

    public static function testPrintAction(): Action
    {
        return Action::make('testPrint')
            ->label(__('Test print'))
            ->icon('heroicon-o-printer')
            ->action(function (ChequeLayout $record) {
                $pdf = app(ChequePrinter::class)->testPrint($record);

                return response()->streamDownload(fn () => print ($pdf->raw()), $pdf->getName(), ['Content-Type' => 'application/pdf']);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChequeLayouts::route('/'),
            'create' => CreateChequeLayout::route('/create'),
            'edit' => EditChequeLayout::route('/{record}/edit'),
        ];
    }
}
