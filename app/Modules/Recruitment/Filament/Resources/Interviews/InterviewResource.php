<?php

namespace App\Modules\Recruitment\Filament\Resources\Interviews;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Recruitment\Filament\Resources\Interviews\Pages\ListInterviews;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\InterviewFeedback;
use App\Modules\Recruitment\Services\Interviews;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use UnitEnum;

/** Interviews and their panel's feedback — docs/saas-launch-plan.md §14.6. Scheduled from an application. */
class InterviewResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Interview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Hiring';

    protected static ?int $navigationSort = 25;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['application.applicant', 'application.vacancy', 'feedback']);
    }

    public static function canCreate(): bool
    {
        // Scheduled from the application, where the candidate and the stage are.
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scheduled_at')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('application.applicant.name')->label('Candidate')->searchable(),
                TextColumn::make('application.vacancy.title')->label('Vacancy')->placeholder('—'),
                TextColumn::make('round')->alignCenter(),
                TextColumn::make('mode')->badge()->color('gray'),
                TextColumn::make('panel_feedback')->label('Feedback')
                    ->state(function (Interview $record): string {
                        $summary = app(Interviews::class)->feedbackSummary($record);

                        return $summary['given'].' of '.$summary['of'].($summary['average'] !== null ? ' · avg '.$summary['average'] : '');
                    })
                    ->color(fn (Interview $r): string => app(Interviews::class)->feedbackSummary($r)['given'] > 0 ? 'success' : 'warning'),
                TextColumn::make('outcome')->badge()->color(fn (string $s): string => match ($s) {
                    'passed' => 'success', 'failed', 'no_show' => 'danger', default => 'warning'
                }),
            ])
            ->filters([
                SelectFilter::make('outcome')->options(['pending' => 'Pending', 'passed' => 'Passed', 'failed' => 'Failed', 'no_show' => 'No show']),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->recordActions([
                Action::make('feedback')
                    ->label('My feedback')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->visible(fn (Interview $r): bool => in_array((int) auth()->id(), array_map('intval', (array) ($r->interviewer_user_ids ?? [])), true))
                    ->fillForm(fn (Interview $record): array => InterviewFeedback::query()->where('interview_id', $record->getKey())->where('user_id', auth()->id())->first()?->only(['rating', 'recommendation', 'strengths', 'concerns']) ?? [])
                    ->schema([
                        Select::make('rating')->options(array_combine(range(1, 5), range(1, 5)))->required(),
                        Select::make('recommendation')->options(InterviewFeedback::RECOMMENDATIONS)->required(),
                        Textarea::make('strengths')->rows(3),
                        Textarea::make('concerns')->rows(3),
                    ])
                    ->action(function (Interview $record, array $data): void {
                        try {
                            app(Interviews::class)->recordFeedback($record, auth()->user(), $data);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Thank you. Your feedback is on the interview.')->send();
                    }),
                Action::make('readFeedback')
                    ->label('Read feedback')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Panel feedback')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->visible(fn (): bool => auth()->user()?->can('ApplicantUpdate') ?? false)
                    ->modalContent(function (Interview $record): HtmlString {
                        $rows = InterviewFeedback::query()->where('interview_id', $record->getKey())->with('interviewer')->get();

                        if ($rows->whereNotNull('submitted_at')->isEmpty()) {
                            return new HtmlString('<p class="text-sm text-gray-500">Nobody on the panel has answered yet.</p>');
                        }

                        $html = $rows->map(function (InterviewFeedback $f): string {
                            if (! $f->isSubmitted()) {
                                return '<li class="text-gray-500">'.e($f->interviewer?->name ?? 'Interviewer').' — not yet</li>';
                            }

                            return '<li><strong>'.e($f->interviewer?->name ?? 'Interviewer').'</strong> — '.e(InterviewFeedback::RECOMMENDATIONS[$f->recommendation] ?? '').' ('.$f->rating.'/5)'
                                .($f->strengths ? '<br><em>Strengths:</em> '.e($f->strengths) : '')
                                .($f->concerns ? '<br><em>Concerns:</em> '.e($f->concerns) : '').'</li>';
                        })->implode('');

                        return new HtmlString('<ul class="text-sm space-y-2">'.$html.'</ul>');
                    }),
                Action::make('decide')
                    ->label('Outcome')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Interview $r): bool => ! $r->isDecided() && (auth()->user()?->can('ApplicantUpdate') ?? false))
                    ->modalDescription('Passed leaves the application where it is — the next round or the offer is your decision. Failed and no-show reject it.')
                    ->schema([
                        Select::make('outcome')->options(['passed' => 'Passed', 'failed' => 'Failed', 'no_show' => 'Did not attend'])->required(),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->action(function (Interview $record, array $data): void {
                        app(Interviews::class)->decide($record, $data['outcome'], $data['notes'] ?? null);
                        Notification::make()->success()->title('Recorded.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInterviews::route('/')];
    }
}
