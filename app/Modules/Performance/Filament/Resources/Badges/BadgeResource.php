<?php

namespace App\Modules\Performance\Filament\Resources\Badges;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Rules\RuleForm;
use App\Modules\Performance\Filament\Resources\Badges\Pages\CreateBadge;
use App\Modules\Performance\Filament\Resources\Badges\Pages\EditBadge;
use App\Modules\Performance\Filament\Resources\Badges\Pages\ListBadges;
use App\Modules\Performance\Models\Badge;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Badge definitions — docs/saas-launch-plan.md §8.4. Awarded from the employee page. */
class BadgeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Badge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?int $navigationSort = 64;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true),
            TextInput::make('description')->maxLength(190)->helperText('What earns it. Shown on the locked badge, so write it as a target.'),
            TextInput::make('icon')->maxLength(60)->placeholder('heroicon-o-star')->helperText('A Heroicon name, optional.'),
            TextInput::make('points')->numeric()->minValue(0)->maxValue(100)->default(5)->helperText('Credited to the merit ledger on every award.'),
            Toggle::make('is_active')->default(true),

            // Who earns it on their own — docs/saas-launch-plan.md §8.9. Empty means awarded by hand only.
            Section::make('Earned by rule')
                ->description('Awarded automatically, once, to anyone the rule matches on the morning run. Leave it empty for a badge somebody gives.')
                ->schema(RuleForm::schema('rule'))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('description')->limit(60),
                TextColumn::make('points')->alignEnd(),
                TextColumn::make('awards_count')->label(__('Awarded'))->counts('awards')->alignEnd(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make(), ReplicateRecordAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBadges::route('/'),
            'create' => CreateBadge::route('/create'),
            'edit' => EditBadge::route('/{record}/edit'),
        ];
    }
}
