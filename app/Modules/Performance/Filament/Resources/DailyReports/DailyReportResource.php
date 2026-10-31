<?php

namespace App\Modules\Performance\Filament\Resources\DailyReports;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Filament\Support\RecordSheet;
use App\Modules\Performance\Filament\Resources\DailyReports\Pages\CreateDailyReport;
use App\Modules\Performance\Filament\Resources\DailyReports\Pages\ListDailyReports;
use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Services\DailyReports;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * Daily reports — docs/saas-launch-plan.md §8.3. The employee files; the
 * manager's morning view is this list for yesterday with the verdict beside
 * each; accept or override writes the ledger.
 */
class DailyReportResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = DailyReport::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?int $navigationSort = 63;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.user', 'decidedBy']);

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** The verdict is the manager's to see; the employee sees it once a manager has decided. */
    public static function verdictVisibleTo(DailyReport $report): bool
    {
        $user = auth()->user();

        return $report->isDecided()
            || ($user?->can('decide', $report) ?? false)
            || ($user?->isSuperAdmin() ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('report_date')->native(false)->default(now())->maxDate(now())->required(),
            Textarea::make('body')
                ->label(__('What you did today'))
                ->rows(10)
                ->required()
                ->maxLength(5000)
                ->columnSpanFull()
                ->helperText('Concrete work done or blocked. Re-filing the same date replaces the earlier report until a manager has decided on it.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('report_date')->date('D d M')->sortable(),
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('body')->limit(80)->wrap(),
                TextColumn::make('verdict')
                    ->badge()
                    ->state(fn (DailyReport $record): string => static::verdictVisibleTo($record) ? ($record->verdict ?? ($record->isDecided() ? '—' : 'pending')) : 'filed')
                    ->color(fn (string $state): string => match ($state) {
                        DailyReport::VERDICT_SUBSTANTIVE => 'success',
                        DailyReport::VERDICT_THIN => 'warning',
                        DailyReport::VERDICT_OFF_TOPIC => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (DailyReport $record): ?string => static::verdictVisibleTo($record) ? $record->verdict_summary : null),
                TextColumn::make('manager_decision')->label(__('Decision'))->badge()->placeholder('—'),
                TextColumn::make('meritEntry.points')->label(__('Points'))->alignEnd()->placeholder('—'),
            ])
            ->defaultSort('report_date', 'desc')
            ->filters([
                SelectFilter::make('manager_decision')
                    ->label(__('Decision'))
                    ->options([DailyReport::DECISION_ACCEPTED => 'Accepted', DailyReport::DECISION_OVERRIDDEN => 'Overridden']),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label(__('Accept verdict'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (DailyReport $record): bool => ! $record->isDecided() && $record->hasVerdict()
                        && (auth()->user()?->can('decide', $record) ?? false))
                    ->modalDescription(fn (DailyReport $record): string => 'Verdict: '.$record->verdict.'. '.$record->verdict_summary
                        .($record->verdict_questions ? ' Questions: '.implode(' ', $record->verdict_questions) : '')
                        .' Accepting writes '.app(DailyReports::class)->pointsForVerdict($record->verdict).' points to the ledger.')
                    ->schema([TextInput::make('note')->maxLength(200)])
                    ->action(function (DailyReport $record, array $data): void {
                        try {
                            app(DailyReports::class)->decide($record, auth()->user(), DailyReport::DECISION_ACCEPTED, null, $data['note'] ?? null);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Accepted.')->send();
                    }),
                Action::make('override')
                    ->label(fn (DailyReport $record): string => $record->hasVerdict() ? 'Override' : 'Decide')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (DailyReport $record): bool => ! $record->isDecided()
                        && (auth()->user()?->can('decide', $record) ?? false))
                    ->schema([
                        TextInput::make('points')->numeric()->minValue(-10)->maxValue(10)->default(0)->required()
                            ->helperText('Your own reading of the report. Zero notes it without touching the ledger.'),
                        TextInput::make('note')->maxLength(200),
                    ])
                    ->action(function (DailyReport $record, array $data): void {
                        try {
                            app(DailyReports::class)->decide($record, auth()->user(), DailyReport::DECISION_OVERRIDDEN, (int) $data['points'], $data['note'] ?? null);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Decided.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyReports::route('/'),
            'create' => CreateDailyReport::route('/create'),
        ];
    }
}
