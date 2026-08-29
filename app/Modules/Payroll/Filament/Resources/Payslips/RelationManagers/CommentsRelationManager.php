<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\RelationManagers;

use App\Modules\Core\Models\Comment;
use App\Modules\Payroll\Models\Payslip;
use App\Support\LandlordUserColumn;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The conversation about a payslip — and, when one has been rejected, the conversation *about the objection*.
 *
 * This listed comments and offered no way to write one, which made it a log rather than a thread: the
 * employee's objection arrived as an email to payroll and everything after it happened outside the
 * application. Both sides can post here now, and both sides can read it — an employee holds `CommentCreate`
 * and `CommentView`, and `CommentPolicy` limits them to records they own, which their own payslip is.
 *
 * **Oldest first**, unlike almost every other table in this application. A conversation read newest-first is
 * a conversation read backwards, and the first row is the objection itself: `recordEmployeeReview()` writes
 * the rejection reason into this thread as its opening comment.
 *
 * **Replying is what unlocks closing the objection.** `Payslip::resolveObjection()` refuses until somebody
 * has replied here, so this tab is not a nicety beside the release — it is the step before it.
 */
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Comments';

    /**
     * The tab's badge: how many replies an open objection is waiting for, which is none or a number.
     *
     * Shown only while an objection is open, because that is the only time the count means anything to act
     * on. A payslip with three ordinary comments is not waiting for anybody.
     */
    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord instanceof Payslip && $ownerRecord->isRejected() && ! $ownerRecord->objectionHasReply()
            ? 'Needs a reply'
            : null;
    }

    public static function getBadgeColor($ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    /**
     * Writable on the view page, which is the whole point of the view page.
     *
     * Filament makes relation managers read-only on a `ViewRecord` by default, on the reasonable assumption
     * that somebody who may not edit the record may not edit the things hanging off it. Here that assumption
     * is exactly backwards: the employee may not edit their payslip and *must* be able to reply about it, and
     * what may be written is decided by `CommentPolicy` — `CommentCreate`, which Employee holds — rather than
     * by the payslip's own update permission.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                // Comments are per-tenant, the author is a landlord user — see
                // LandlordUserColumn.
                TextColumn::make('user.name')
                    ->label('Author')
                    ->description(fn (Comment $record): ?string => $record->getKey() === $this->getOwnerRecord()->review_objection_comment_id
                        ? 'the objection'
                        : null)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => LandlordUserColumn::sort($query, $direction, 'name')),

                TextColumn::make('body')
                    ->wrap()
                    ->searchable(),

                IconColumn::make('resolved_at')
                    ->label('Resolved')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            // Oldest first: this is a conversation, and the objection is the top of it.
            ->defaultSort('created_at', 'asc')
            ->headerActions([
                CreateAction::make()
                    ->label('Reply')
                    ->modalHeading(fn (): string => $this->getOwnerRecord()->isRejected()
                        ? 'Reply to the objection'
                        : 'Add a comment')
                    ->modalDescription(fn (): ?string => $this->getOwnerRecord()->isRejected()
                        ? 'The employee said: "'.($this->getOwnerRecord()->employee_rejection_reason ?: 'no reason recorded')
                            .'". Your reply is on the payslip they can open.'
                        : null)
                    ->schema([
                        Textarea::make('body')
                            ->label('Comment')
                            ->required()
                            ->maxLength(1000)
                            ->rows(4),
                    ])
                    // The author is whoever is signed in. Not settable on the form, for the obvious reason.
                    ->mutateDataUsing(fn (array $data): array => $data + ['user_id' => auth()->id()]),
            ]);
    }
}
