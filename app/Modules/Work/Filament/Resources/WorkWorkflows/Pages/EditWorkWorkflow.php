<?php

namespace App\Modules\Work\Filament\Resources\WorkWorkflows\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkWorkflows\WorkWorkflowResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * A workflow's own page: its name and defaults above, its statuses below (StatusesRelationManager).
 *
 * The resource had only its list, so the statuses relation manager it declares was never shown and a
 * board's "Edit workflow" link pointed at a route that did not exist. This is where a status is added,
 * renamed or reordered — the statuses every board on the workflow maps into its columns.
 */
class EditWorkWorkflow extends EditRecord
{
    protected static string $resource = WorkWorkflowResource::class;

    /** Back to the list after a save, like every other edit page (CrudRedirectsToListingTest). */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-workflows', 'Workflows: Help'),
            DeleteAction::make(),
            ReplicateRecordAction::make(),
        ];
    }
}
