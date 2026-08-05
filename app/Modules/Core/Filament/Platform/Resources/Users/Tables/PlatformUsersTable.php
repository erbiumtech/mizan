<?php

namespace App\Modules\Core\Filament\Platform\Resources\Users\Tables;

use App\Modules\Core\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlatformUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),

                IconColumn::make('is_super_admin')
                    ->label('Platform')
                    ->boolean(),

                // The question this screen is for: who is this person, across the
                // installation. On the company panel the answer is always "here".
                TextColumn::make('companies.name')
                    ->label('Companies')
                    ->badge()
                    ->placeholder('none — cannot sign in anywhere')
                    ->limitList(3)
                    ->expandableLimitedList(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('companies')
                    ->label('Company')
                    ->relationship('companies', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('is_super_admin')
                    ->label('Platform admins')
                    ->query(fn ($query) => $query->where('is_super_admin', true)),

                // An account attached to nothing can sign in nowhere, which is easy to
                // create by forgetting the last field on the form and invisible afterwards.
                Filter::make('no_company')
                    ->label('In no company')
                    ->query(fn ($query) => $query->whereDoesntHave('companies')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Deleting the last platform admin would lock everybody out of this
                    // panel, including whoever did it. Refused on the User model, not
                    // here: Gate::before grants a super admin every ability, so a rule
                    // that has to hold for everyone cannot live in a policy — and it must
                    // hold for the console and a queued job too.
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
