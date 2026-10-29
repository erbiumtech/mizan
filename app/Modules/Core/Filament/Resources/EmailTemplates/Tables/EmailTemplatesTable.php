<?php

namespace App\Modules\Core\Filament\Resources\EmailTemplates\Tables;

use App\Modules\Core\Models\EmailTemplate;
use App\Support\TemplatedMail;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Throwable;

class EmailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Email')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')->placeholder('shipped wording')->wrap(),

                // Which parts are overridden, so it is obvious at a glance that a
                // template changing only the subject leaves the rest alone.
                TextColumn::make('overrides')
                    ->label('Overrides')
                    ->state(fn (EmailTemplate $record): string => collect([
                        'subject' => $record->subject,
                        'greeting' => $record->greeting,
                        'body' => $record->body,
                        'closing' => $record->closing,
                    ])->filter()->keys()->implode(', ') ?: 'nothing'),

                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->defaultSort('key')
            ->recordActions([
                /*
                 * Preview and test send — docs/saas-launch-plan.md §14.7.
                 *
                 * Both render the template through the same code the real mail uses, on a message whose
                 * own wording is a visible placeholder. That is what makes the preview worth looking at:
                 * where the template leaves a part alone, the preview says so in words rather than
                 * quietly showing the shipped sentence as though the template had written it.
                 */
                Action::make('preview')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (EmailTemplate $record): string => 'Preview: '.$record->keyLabel())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (EmailTemplate $record): HtmlString {
                        $mail = TemplatedMail::previewOf($record);

                        // In an iframe, because the rendered mail brings its own stylesheet and
                        // letting that loose in the panel restyles the page around it.
                        return new HtmlString(
                            '<p class="mb-3 text-sm"><strong>Subject:</strong> '.e($mail->subject).'</p>'
                            .'<div class="overflow-hidden rounded-lg border">'
                            .'<iframe class="h-[32rem] w-full bg-white" srcdoc="'.e($mail->render()).'"></iframe>'
                            .'</div>'
                        );
                    }),

                Action::make('test')
                    ->label('Send a test')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'One copy, with made-up figures, to '.(auth()->user()?->email ?? 'you').'.')
                    ->action(function (EmailTemplate $record): void {
                        $to = auth()->user()?->email;

                        if (! $to) {
                            Notification::make()->warning()->title('Your account has no email address.')->send();

                            return;
                        }

                        $mail = TemplatedMail::previewOf($record);

                        try {
                            Mail::html($mail->render(), fn (Message $message) => $message->to($to)->subject($mail->subject));
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('It could not be sent.')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("Sent to {$to}.")->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
