<?php

namespace App\Modules\Crm\Filament\Resources\WeeklySalesReports;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Filament\Support\RecordSheet;
use App\Modules\Crm\Filament\Resources\WeeklySalesReports\Pages\CreateWeeklySalesReport;
use App\Modules\Crm\Filament\Resources\WeeklySalesReports\Pages\EditWeeklySalesReport;
use App\Modules\Crm\Filament\Resources\WeeklySalesReports\Pages\ListWeeklySalesReports;
use App\Modules\Crm\Models\WeeklySalesReport;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The weekly form — docs/saas-launch-plan.md §14.10.
 *
 * **For a company without the pipeline.** One with it has every one of these figures
 * already, and the Weekly Sales report reads them instead; the form is for a company
 * that has a sales team and no CRM, where the alternative is no report at all.
 *
 * Five numbers and a sentence, and it stays five. A form with twenty fields is a form
 * people stop filling in, and a weekly report nobody fills in is worse than none,
 * because the gaps read as nothing having happened.
 */
class WeeklySalesReportResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = WeeklySalesReport::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Weekly Sales Form';

    protected static ?string $modelLabel = 'weekly sales form';

    protected static ?int $navigationSort = 29;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('employee.user');

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label(__('Rep'))
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')
                    ->orderBy('employee_id')->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()
                ->required()
                ->default(fn (): ?int => app(EmployeeAccess::class)->ownEmployeeId(auth()->user())),

            DatePicker::make('week_starting')
                ->label(__('Week starting'))
                ->native(false)
                ->required()
                ->default(fn (): string => WeeklySalesReport::weekStarting())
                // Snapped to the Monday so two people cannot disagree about which week
                // they mean, and so the unique index does its job.
                ->dehydrateStateUsing(fn (?string $state): string => WeeklySalesReport::weekStarting($state))
                ->helperText('Any day in the week; it is filed under that week\'s Monday.'),

            TextInput::make('visits')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('calls')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('quotes')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('orders')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('order_value')->label(__('Value of orders'))->numeric()->minValue(0)->default(0),

            Textarea::make('note')
                ->rows(3)
                ->columnSpanFull()
                ->label(__('Anything worth saying'))
                ->helperText('One or two sentences. What is stuck, what is about to land.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('week_starting')->label(__('Week of'))->date('d M Y')->sortable(),
                TextColumn::make('employee.display_label')->label(__('Rep'))->searchable(),
                TextColumn::make('visits')->alignRight(),
                TextColumn::make('calls')->alignRight(),
                TextColumn::make('quotes')->alignRight(),
                TextColumn::make('orders')->alignRight(),
                TextColumn::make('order_value')->label(__('Value'))->numeric(0)->alignRight(),
                TextColumn::make('note')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('week_starting', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWeeklySalesReports::route('/'),
            'create' => CreateWeeklySalesReport::route('/create'),
            'edit' => EditWeeklySalesReport::route('/{record}/edit'),
        ];
    }
}
