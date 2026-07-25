<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        Select::make('guard_name')
                            ->options(['web' => 'web'])
                            ->default('web')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Permissions')
                    ->description('Assign permissions, grouped by module.')
                    ->schema(self::permissionCheckboxes())
                    ->columns(1)
                    ->collapsible(),
            ]);
    }

    /**
     * A CheckboxList per permission group. State is handled manually by the
     * Create/Edit pages (see SyncsGroupedPermissions), so these are not
     * dehydrated into the role's own attributes.
     *
     * @return array<CheckboxList>
     */
    public static function permissionCheckboxes(): array
    {
        return self::groupedPermissions()
            ->map(fn (Collection $perms, string $group) => CheckboxList::make(self::groupKey($group))
                ->label($group)
                ->options($perms->pluck('name', 'id')->toArray())
                ->columns(3)
                ->bulkToggleable()
                ->dehydrated(false))
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, Collection<int, Permission>>
     */
    public static function groupedPermissions(): Collection
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy('group');
    }

    public static function groupKey(string $group): string
    {
        return 'perm_'.Str::slug($group, '_');
    }
}
