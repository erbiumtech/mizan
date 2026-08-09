<?php

namespace App\Modules\Core\Filament\Platform\Resources\Roles\Tables;

use App\Modules\Core\Models\Company;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class PlatformRolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Company first, and sorted by it, because that is what stops the list reading
            // as duplicates: five role names across N companies with nothing to tell them
            // apart is what somebody reported after provisioning a second company, and it
            // is why the per-company relation manager was the only view of roles until now.
            ->defaultSort('company_name')
            ->columns([
                TextColumn::make('company_name')
                    ->label('Company')
                    // Ordering by the select alias is fine; searching it is not — an alias
                    // cannot be referenced in WHERE — so the search matches companies and
                    // filters roles by the ones it found.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('company_name', $direction))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereIn(
                            config('permission.column_names.team_foreign_key', 'company_id'),
                            Company::query()->select('id')->where('name', 'like', "%{$search}%"),
                        ))
                    // A role whose company row is gone is not a display curiosity — it is
                    // reachable by nobody, and this screen is where it can be seen.
                    ->placeholder('no company')
                    ->weight('medium'),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('guard_name')
                    ->label('Guard')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'gray' : 'warning')
                    ->formatStateUsing(fn ($state): string => $state ?: 'none'),

                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make(config('permission.column_names.team_foreign_key', 'company_id'))
                    ->label('Company')
                    ->options(fn (): array => Company::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->recordActions([
                // Editing happens where the permissions mean something. See the note on
                // PlatformRoleResource for why there is no edit page on this panel.
                Action::make('openOnCompanyPanel')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Role $record): ?string => self::companyPanelUrl($record))
                    ->hidden(fn (Role $record): bool => self::companyPanelUrl($record) === null)
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No roles in the installation')
            ->emptyStateDescription(
                'Provisioning a company creates its roles. A company that has none is listed on the '
                .'Companies screen, which can create them.'
            );
    }

    /**
     * This role on its own company's panel, or null when it has no company to open.
     *
     * Built from the slug rather than through RoleResource::getUrl(), matching how the
     * Companies table crosses into the other panel: that panel's URLs are tenant-scoped, and
     * generating one from here means naming the tenant anyway.
     */
    protected static function companyPanelUrl(Role $role): ?string
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'company_id');
        $company = $role->getAttribute($teamKey)
            ? Company::find($role->getAttribute($teamKey))
            : null;

        return $company
            ? "/admin/{$company->slug}/roles/{$role->getKey()}/edit"
            : null;
    }
}
