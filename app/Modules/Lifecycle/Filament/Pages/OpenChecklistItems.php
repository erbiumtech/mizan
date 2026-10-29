<?php

namespace App\Modules\Lifecycle\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Lifecycle\Models\EmployeeChecklistItem;
use App\Modules\Lifecycle\Services\ChecklistService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The tick-and-waive screen — docs/saas-launch-plan.md §14.5: every open checklist item, overdue first. */
class OpenChecklistItems extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.simple-table';

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Checklist Items';

    protected static ?int $navigationSort = 54;

    public static function canAccess(): bool
    {
        return modules()->enabled('lifecycle') && (auth()->user()?->can('ChecklistUpdate') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EmployeeChecklistItem::query()->with('checklist.employee.user')->whereNull('completed_at')->whereNull('waived_at'))
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('due_on')->date('d M Y')->sortable()->color(fn (EmployeeChecklistItem $i): string => $i->due_on?->isPast() ? 'danger' : 'gray'),
                TextColumn::make('checklist.employee.display_label')->label('Employee'),
                TextColumn::make('checklist.kind')->label('Checklist')->badge()->color('gray'),
                TextColumn::make('title')->description(fn (EmployeeChecklistItem $i): ?string => $i->kind === 'auto' ? 'closes from the records' : null),
                TextColumn::make('kind')->badge()->color('gray'),
                TextColumn::make('owner_role')->label('Owner')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (EmployeeChecklistItem $i): bool => $i->kind === 'link' && filled($i->link_url))
                    ->url(fn (EmployeeChecklistItem $i): string => (string) $i->link_url, shouldOpenInNewTab: true),
                Action::make('tick')->label('Done')->icon('heroicon-o-check')->color('success')
                    ->action(function (EmployeeChecklistItem $record): void {
                        app(ChecklistService::class)->complete($record, auth()->user());
                        Notification::make()->success()->title('Done.')->send();
                    }),
                Action::make('waive')->label('Waive')->icon('heroicon-o-hand-raised')->color('gray')
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (EmployeeChecklistItem $record, array $data): void {
                        app(ChecklistService::class)->waive($record, auth()->user(), $data['reason']);
                        Notification::make()->success()->title('Waived.')->send();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('checklist-items', 'Checklist Items: Help'),
            Action::make('autoCheck')->label('Run auto-checks')->icon('heroicon-o-arrow-path')->color('gray')
                ->action(function (): void {
                    $closed = 0;

                    foreach (\App\Modules\Lifecycle\Models\EmployeeChecklist::query()->whereNull('completed_on')->with('employee')->get() as $checklist) {
                        $closed += app(ChecklistService::class)->autoCheck($checklist);
                    }

                    Notification::make()->success()->title("{$closed} item(s) closed from the records.")->send();
                }),
        ];
    }
}
