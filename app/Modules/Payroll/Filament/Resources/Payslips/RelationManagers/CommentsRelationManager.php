<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\RelationManagers;

use App\Modules\Core\Models\Comment;
use App\Modules\Payroll\Models\Payslip;
use App\Support\LandlordUserColumn;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

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

    /** Is this the comment the rejection reason was written into? */
    protected function isObjection(Comment $comment): bool
    {
        return $comment->getKey() === $this->getOwnerRecord()->review_objection_comment_id;
    }

    /**
     * Close a comment off — and, if it is the objection, everything that hangs on it.
     *
     * The two branches are deliberately not the same act: an ordinary comment is a note somebody has dealt
     * with, and the objection is a decision to pay over a complaint. The second is the model's, so that the
     * release, the record of who decided, and the email to the employee cannot be got wrong by a screen.
     */
    protected function markSolved(Comment $comment): void
    {
        if ($this->isObjection($comment)) {
            try {
                $this->getOwnerRecord()->resolveObjection();

                Notification::make()
                    ->title('Objection closed; the salary can be released.')
                    ->success()
                    ->send();
            } catch (InvalidArgumentException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }

            return;
        }

        $comment->update(['resolved_at' => now(), 'resolved_by' => auth()->id()]);

        Notification::make()->title('Marked solved.')->success()->send();
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
            ->recordActions([
                /*
                 * **Mark solved**, on the thread itself — the button an administrator looks for here.
                 *
                 * It existed only on Core's own Comments screen, which lists every comment in the company;
                 * on the payslip, where somebody is actually reading the exchange, there was no way to close
                 * anything off.
                 *
                 * **On the objection it does the whole thing.** Resolving the comment and leaving the payslip
                 * rejected would look finished and change nothing: the state would still read *rejected* and
                 * the salary would still be held. So this delegates to `Payslip::resolveObjection()`, which
                 * resolves this comment, records the decision, releases the payment and tells the employee.
                 * Two buttons that half-agree is the failure this avoids — `CloseObjectionAction` on the
                 * pages and this one in the thread reach the same method.
                 *
                 * On any other comment it is what it says: this bit of the conversation is dealt with.
                 */
                Action::make('resolveComment')
                    ->label('Mark solved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Comment $record): bool => ! $record->isResolved()
                        && (auth()->user()?->can('resolve', $record) ?? false))
                    ->disabled(fn (Comment $record): bool => $this->isObjection($record)
                        && ! $this->getOwnerRecord()->objectionHasReply())
                    ->tooltip(fn (Comment $record): ?string => $this->isObjection($record)
                        && ! $this->getOwnerRecord()->objectionHasReply()
                        ? 'Reply to the objection first.'
                        : null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Comment $record): string => $this->isObjection($record)
                        ? 'Close the objection and release the salary'
                        : 'Mark this comment solved')
                    ->modalDescription(fn (Comment $record): ?string => $this->isObjection($record)
                        ? 'This is the objection itself. Marking it solved releases the salary for payment '
                            .'and emails the employee. The objection stays on the record.'
                        : null)
                    ->action(fn (Comment $record) => $this->markSolved($record)),
            ])
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
