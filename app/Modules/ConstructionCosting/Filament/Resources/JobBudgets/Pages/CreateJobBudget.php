<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\Pages;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\JobBudgets\JobBudgetResource;
use App\Modules\ConstructionCosting\Services\BudgetService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateJobBudget extends CreateRecord
{
    protected static string $resource = JobBudgetResource::class;

    /**
     * Created through `BudgetService`, not by the form.
     *
     * The service numbers the version, and where the job already has a current budget it **copies its lines**.
     * §3.5's reason: a revision after variation twelve differs from the budget before it by a handful of lines, and
     * retyping four hundred is how a revision comes to disagree with the budget it was supposed to revise.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $job = Job::query()->findOrFail($data['job_id']);

        $version = app(BudgetService::class)->createVersion($job, $data['name'], $data['kind']);

        // The two fields the service does not take, because neither is a decision it makes.
        $version->update([
            'effective_from' => $data['effective_from'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $version;
    }

    /**
     * Straight to the lines, which is the next thing anybody does.
     *
     * A version with no lines cannot be approved, so the list would only be somewhere to click "edit" from.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
