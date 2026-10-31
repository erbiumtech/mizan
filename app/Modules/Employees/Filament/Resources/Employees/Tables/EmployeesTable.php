<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Core\Models\Bank;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Filament\Support\IncomeCertificatesBulkAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmployeeErasure;
use App\Modules\Employees\Services\EmployeeMoves;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use App\Support\ResourceContributions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        // Painted before it is filled.
        //
        // Without this the whole page waits on this table's query, its count and
        // its filters before a single pixel arrives; with it the shell and the
        // heading render immediately and the rows follow in a second request.
        // Applied to the long lists rather than to every table — on a table of
        // twenty rows it buys a round trip and nothing else.
        //
        // See docs/page-load-performance-plan.md.
        return $table
            ->deferLoading()
            ->columns([
                // Nova ID: canSee Administrators only.
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()?->isAdministrator() ?? false),

                TextColumn::make('employee_id')
                    ->label(__('Employee ID'))
                    ->searchable(),

                // Name and email live on the landlord `users` table while
                // employees live in the tenant database, so these cannot use
                // Filament's relationship search/sort — see LandlordUserColumn.
                TextColumn::make('user.name')
                    ->label(__('Employee Name'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => LandlordUserColumn::sort($query, $direction, 'name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => LandlordUserColumn::search($query, $search, ['name'])),

                TextColumn::make('user.email')
                    ->label(__('Company Email'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => LandlordUserColumn::search($query, $search, ['email'])),

                TextColumn::make('personal_email')
                    ->label(__('Personal Email'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('date_of_birth')
                    ->label(__('Date of Birth'))
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('secondary_phone')
                    ->label(__('Secondary Phone'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('is_active')
                    ->label(__('Status'))
                    ->formatStateUsing(fn ($state): string => (int) $state === 1 ? 'Active' : 'Inactive'),

                TextColumn::make('bank_code')
                    ->searchable(),

                TextColumn::make('bank_short_code')
                    ->searchable(),

                ...CustomFieldsSchema::tableColumns(Employee::class),
            ])
            // A grid of cards or the list — docs/saas-launch-plan.md §14.2; the choice is kept for the session.
            ->contentGrid(fn (): ?array => session('employees.layout') === 'grid' ? ['md' => 2, 'xl' => 3] : null)
            ->filters([
                ...CustomFieldsSchema::tableFilters(Employee::class),

                // The directory's own two — docs/saas-launch-plan.md §14.2.
                SelectFilter::make('department')
                    ->options(fn (): array => options('employees.department')),
                SelectFilter::make('status')
                    ->options(Employee::STATUSES),

                SelectFilter::make('location_id')
                    ->label(__('Location'))
                    ->options(fn (): array => \App\Modules\Core\Models\WorkLocation::options()),

                SelectFilter::make('employee_name')
                    ->label(__('Employee Name'))
                    ->attribute('id')
                    ->options(fn (): array => static::accessibleEmployees()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->name ?? 'Unknown'])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('employee_email')
                    ->label(__('Company Email'))
                    ->attribute('id')
                    ->options(fn (): array => static::accessibleEmployees()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->email ?? 'No Email'])
                        ->toArray())
                    ->searchable(),

                /*
                 * Which bank an employee is paid into.
                 *
                 * This replaces the read-only Employees list that used to hang off BankResource as a
                 * relation manager. That list needed `Bank::employees()`, which is the relation that kept
                 * the bank table tied to the Employees module and stopped either being packaged
                 * separately (docs/module-packaging-plan.md §7). The question it answered — "who banks
                 * here" — belongs on this screen anyway: it composes with the other filters, it obeys the
                 * downline scoping every other filter here obeys, and it is where somebody looking for an
                 * employee already is.
                 *
                 * Filters on `bank_id` rather than on the denormalised `bank_code`, because two banks can
                 * share a code in a chart that has been edited by hand and the id cannot.
                 */
                SelectFilter::make('bank_id')
                    ->label(__('Bank'))
                    ->options(fn (): array => Bank::query()
                        ->orderBy('bank_name')
                        ->pluck('bank_name', 'id')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                /*
                 * Subject access and erasure — docs/saas-launch-plan.md §14.8.
                 *
                 * Together on the row they are about, because they are two halves of the same
                 * request: somebody asks what is held, reads it, and then asks for it to go.
                 */
                Action::make('discloseRecord')
                    ->label(__('Everything we hold'))
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->visible(fn (Employee $record): bool => auth()->user()?->can('disclose', $record) ?? false)
                    ->action(function (Employee $record) {
                        $erasure = app(EmployeeErasure::class);
                        $json = json_encode($erasure->disclose($record), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                        return response()->streamDownload(fn () => print $json, $erasure->filename($record), ['Content-Type' => 'application/json']);
                    }),

                Action::make('erase')
                    ->label(__('Erase this person'))
                    ->icon('heroicon-o-fire')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Erase this person from the record')
                    ->modalDescription('The phone, address, identity card, bank details and scans are emptied and '
                        .'the scans are deleted. Payslips and ledger entries are kept: they are statutory records '
                        .'and this never touches them. It cannot be undone.')
                    ->modalSubmitActionLabel('Erase')
                    ->visible(fn (Employee $record): bool => (auth()->user()?->can('erase', $record) ?? false) && ! $record->isAnonymised())
                    ->action(function (Employee $record): void {
                        try {
                            app(EmployeeErasure::class)->erase($record);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Nothing was erased')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Erased. The records that had to stay are still there.')->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::activateBulkAction(),
                    // Certificates for many at once — docs/bexio-gap-plan.md Phase 5, item 18. The income
                    // certificate is this module's; the tax certificate is Payroll's and arrives from there.
                    IncomeCertificatesBulkAction::make(),
                    ...ResourceContributions::bulkActionsFor(EmployeeResource::class),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Employees the current user may filter by (own + downline; all if privileged). */
    protected static function accessibleEmployees(): Collection
    {
        $query = Employee::with('user');

        return app(EmployeeAccess::class)
            ->scopeAccessibleEmployees($query, auth()->user())
            ->get();
    }

    /**
     * Switch on, give a login, open the leave year — docs/saas-launch-plan.md §14.2. For the rows an
     * import brought in without a user; the invitation goes to the personal email.
     */
    protected static function activateBulkAction(): BulkAction
    {
        return BulkAction::make('activateEmployees')
            ->label(__('Activate'))
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Each selected employee is made active, given a login invited from their personal email if they have none, and their leave year opened.')
            ->visible(fn (): bool => auth()->user()?->can('EmployeeUpdate') ?? false)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $invited = 0;
                $problems = [];

                foreach ($records as $employee) {
                    $result = app(EmployeeMoves::class)->activate($employee, auth()->user());
                    $invited += (int) $result['invited'];

                    if ($result['problem'] !== null) {
                        $problems[] = $employee->display_label.': '.$result['problem'];
                    }
                }

                Notification::make()
                    ->title($records->count().' activated, '.$invited.' invited.')
                    ->body($problems === [] ? null : implode('; ', $problems))
                    ->{$problems === [] ? 'success' : 'warning'}()
                    ->persistent($problems !== [])
                    ->send();
            });
    }
}
