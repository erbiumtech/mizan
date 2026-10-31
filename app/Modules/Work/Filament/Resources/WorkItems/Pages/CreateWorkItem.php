<?php

namespace App\Modules\Work\Filament\Resources\WorkItems\Pages;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Services\WorkAssists;
use App\Modules\Work\Services\WorkItems;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkItem extends CreateRecord
{
    protected static string $resource = WorkItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->draftAction(),
        ];
    }

    /**
     * Draft from a sentence — docs/project-management-plan.md §12, assist 1.
     *
     * The reply is shown as a prefilled form and saved only when somebody saves it; the model never
     * touches the write path. Absent when `ai.php` has no key.
     */
    private function draftAction(): Action
    {
        return Action::make('draft')
            ->label('Draft from a sentence')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn (): bool => app(WorkAssists::class)->isAvailable())
            ->modalDescription('One sentence about what is wrong or wanted. The form below is filled in for you to check; nothing is saved until you save it.')
            ->schema([
                Textarea::make('sentence')
                    ->label('What is it?')
                    ->rows(3)
                    ->required()
                    ->maxLength(1000)
                    ->placeholder("Users can't export the March payroll as PDF on Safari"),
            ])
            ->action(function (array $data): void {
                $projectId = $this->form->getRawState()['project_id'] ?? null;
                $project = $projectId !== null ? Project::query()->find($projectId) : null;

                $draft = app(WorkAssists::class)->draft((string) $data['sentence'], $project);

                if ($draft === null) {
                    Notification::make()->warning()->title('No draft')->body('The model did not answer. Fill the form in by hand.')->send();

                    return;
                }

                $this->form->fill([
                    ...$this->form->getRawState(),
                    'title' => $draft['title'],
                    'description' => $draft['description'],
                    'type' => $draft['type'],
                    'priority' => $draft['priority'],
                ]);

                Notification::make()->success()
                    ->title('Drafted — check it before saving')
                    ->body($draft['labels'] !== [] ? 'Suggested labels: '.implode(', ', $draft['labels']) : null)
                    ->send();
            });
    }

    /**
     * Through the service, because the key and the number are not the form's to invent.
     *
     * `WorkItems::create()` takes the sequence under a row lock on the project — see §3.1 and the
     * service's own comment for why `WRK-12` appearing twice is the one failure here that cannot be
     * undone later.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $project = Project::query()->findOrFail($data['project_id']);

        return app(WorkItems::class)->create($project, $data, auth()->user());
    }
}
