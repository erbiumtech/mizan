<?php

namespace App\Modules\Leave\Filament\Resources\LeaveEntitlements\Schemas;

use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveYear;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LeaveEntitlementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                    $search,
                    EmployeeOptions::accessibleScope(),
                ))
                ->preload()
                ->required(),

            Select::make('leave_type_id')
                ->label('Leave type')
                ->options(fn (): array => LeaveType::query()
                    ->active()
                    ->get()
                    // Uncounted types have no balance, so an entitlement for one
                    // would be a credit of nothing that then reads as "0 left".
                    ->filter->isCounted()
                    ->pluck('label', 'id')
                    ->all())
                ->searchable()
                ->required(),

            // Stamped rather than derived, which is what makes leave.year_basis safe
            // to change: this window is the one this entitlement keeps for ever, and
            // a later change of basis governs only the entitlements created after it.
            DatePicker::make('leave_year_start')
                ->native(false)
                ->required()
                ->default(fn (): string => app(LeaveYear::class)->basis() === LeaveYear::BASIS_FISCAL
                    ? (now()->month >= 7 ? now()->startOfYear()->month(7) : now()->subYear()->startOfYear()->month(7))->toDateString()
                    : now()->startOfYear()->toDateString())
                ->helperText('The leave year this entitlement belongs to. It never moves, even if the company later changes its leave-year basis.'),

            DatePicker::make('leave_year_end')
                ->native(false)
                ->required()
                ->afterOrEqual('leave_year_start')
                ->default(fn (): string => app(LeaveYear::class)->basis() === LeaveYear::BASIS_FISCAL
                    ? (now()->month >= 7 ? now()->startOfYear()->month(7)->addYear()->subDay() : now()->startOfYear()->month(6)->endOfMonth())->toDateString()
                    : now()->endOfYear()->toDateString()),

            TextInput::make('opening_days')
                ->label('Opening days')
                ->numeric()
                ->step(0.5)
                ->default(0)
                ->helperText('What they already had when this module started. Set-up only — later corrections are adjustments, so they keep their reason.'),

            TextInput::make('accrued_days')
                ->label('Accrued days')
                ->numeric()
                ->step(0.5)
                ->default(0)
                ->helperText('Normally written by the nightly leave:open-year command from the type\'s accrual method.'),

            TextInput::make('carried_in_days')
                ->label('Carried in')
                ->numeric()
                ->step(0.5)
                ->default(0)
                ->helperText('Written by the year-end roll, capped by the type. Typing it here bypasses that cap.'),
        ]);
    }
}
