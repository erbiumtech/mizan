<?php

namespace App\Modules\Employees\Filament\Resources\Designations;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Filament\Resources\Designations\Pages\CreateDesignation;
use App\Modules\Employees\Filament\Resources\Designations\Pages\EditDesignation;
use App\Modules\Employees\Filament\Resources\Designations\Pages\ListDesignations;
use App\Modules\Employees\Models\Designation;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Designations as rows, with a pay grade — docs/saas-launch-plan.md §14.2. */
class DesignationResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Designation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 41;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true)->helperText('Renaming follows the employees who hold it.'),
            TextInput::make('code')->maxLength(20),
            TextInput::make('level')->numeric()->minValue(1)->maxValue(99)->helperText('1 is the top. Orders the dropdown.'),
            TextInput::make('pay_grade_min')->label(__('Pay grade from'))->numeric()->minValue(0)->helperText('Monthly basic. A package outside the band warns and never blocks.'),
            TextInput::make('pay_grade_max')->label(__('Pay grade to'))->numeric()->minValue(0)->gte('pay_grade_min'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('code')->placeholder('—'),
                TextColumn::make('level')->placeholder('—')->sortable(),
                TextColumn::make('grade')->label(__('Pay grade'))->state(fn (Designation $r): string => $r->hasPayGrade()
                    ? number_format((float) $r->pay_grade_min).' – '.($r->pay_grade_max !== null ? number_format((float) $r->pay_grade_max) : '∞')
                    : '—'),
                TextColumn::make('employees_count')->counts('employees')->label(__('People')),
                IconColumn::make('is_active')->boolean()->label(__('Active')),
            ])
            ->defaultSort('level')
            ->recordActions([EditAction::make(), DeleteAction::make(), ReplicateRecordAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDesignations::route('/'),
            'create' => CreateDesignation::route('/create'),
            'edit' => EditDesignation::route('/{record}/edit'),
        ];
    }
}
