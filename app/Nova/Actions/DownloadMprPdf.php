<?php

namespace App\Nova\Actions;

use App\Models\User;
use App\Services\MprPdfService;
use App\Services\RoleService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class DownloadMprPdf extends Action
{
    use InteractsWithQueue, Queueable;

    // Header par jo naam show hoga
    public $name = 'Generate / Download PDF';

    /**
     * Perform the action on the given models.
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $pdfService = new MprPdfService;

        $userId = $fields->user_id ?? auth()->id();

        if ($userId) {
            $result = $pdfService->generateComparisonReport($userId);

            if (! $result || $result['empty']) {
                return Action::danger('This user has no MPR Record in database');
            }

            $result['pdf']->save(storage_path('app/public/'.$result['file_name']));

            return Action::openInNewTab(url('storage/'.$result['file_name']));
        }

        return Action::danger('Select any User from dropdown!');
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request)
    {
        $user = $request->user();

        if ($user) {
            $roleService = new RoleService;

            // 💡 DIRECT SERVICE CALL HERE TOO:
            if ($roleService->isUser($user) && ! $roleService->isAdmin($user)) {
                return [
                    Select::make('User', 'user_id')
                        ->options([
                            $user->id => $user->name,
                        ])
                        ->default($user->id)
                        ->help('Your profile is automatically selected for this action.'),
                ];
            }
        }

        return [
            Select::make('Select User', 'user_id')
                ->options(User::where('status', 1)->pluck('name', 'id'))
                ->searchable()
                ->rules('required')
                ->help('Generate Recent Two Pdfs of Selected User'),
        ];
    }
}
