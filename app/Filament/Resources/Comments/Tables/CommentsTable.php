<?php

namespace App\Filament\Resources\Comments\Tables;

use App\Models\Comment;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class CommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Nova: MorphTo `commentable` (On), types [Payslip], exceptOnForms.
                TextColumn::make('commentable')
                    ->label('On')
                    ->state(fn (Comment $record): string => class_basename($record->commentable_type).' #'.$record->commentable_id),

                // Nova: BelongsTo `user` (By) → User, exceptOnForms.
                TextColumn::make('user.name')
                    ->label('By'),

                TextColumn::make('body')
                    ->label('Comment')
                    ->searchable()
                    ->wrap(),

                // Nova: Badge "Status" — computed open/resolved.
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Comment $record): string => $record->isResolved() ? 'resolved' : 'open')
                    ->color(fn (string $state): string => match ($state) {
                        'resolved' => 'success',
                        'open' => 'warning',
                        default => 'gray',
                    }),

                // Nova: DateTime `created_at` (Created) — exceptOnForms, sortable.
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                // Nova action: ResolveComment ("Mark Resolved"), showInline,
                // canRun can('resolve', $comment) -> policy resolve() = CommentResolve permission.
                Action::make('resolveComment')
                    ->label('Mark Resolved')
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->visible(fn (Comment $record): bool => ! $record->isResolved()
                        && (auth()->user()?->can('resolve', $record) ?? false))
                    ->action(fn (Comment $record) => self::resolve(collect([$record]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('resolveCommentBulk')
                        ->label('Mark Resolved')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()?->can('CommentResolve') ?? false)
                        ->action(fn (Collection $records) => self::resolve($records)),
                ]),
            ]);
    }

    /**
     * Mirror of Nova ResolveComment@handle: set resolved_at + resolved_by.
     */
    protected static function resolve(Collection $records): void
    {
        foreach ($records as $comment) {
            $comment->update([
                'resolved_at' => now(),
                'resolved_by' => auth()->id(),
            ]);
        }
    }
}
