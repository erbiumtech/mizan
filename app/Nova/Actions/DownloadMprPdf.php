<?php

namespace App\Nova\Actions;

use App\Models\User;
use App\Services\MprPdfService;
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

    public $name = 'Generate / Download PDF';

    public function handle(ActionFields $fields, Collection $models)
    {
        $pdfService = new MprPdfService;
        $userId = $fields->user_id ?? auth()->id();

        if ($userId) {

            // User model query
            $user = User::find($userId);
            $result = $pdfService->generateComparisonReport($userId);

            if (! $result || $result['empty']) {
                return Action::danger('This user has no MPR Record in database');
            }

            $userName = $user->name ?? 'User';
            $cleanName = str_replace([' ', '/', '\\'], '_', $userName);
            $customFileName = $cleanName . '_Comparison_' . time() . '.pdf';

            $directory = storage_path('app/public/Mpr');

            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            $result['pdf']->save($directory . '/' . $customFileName);

            return Action::openInNewTab(url('storage/Mpr/' . $customFileName));
        }

        return Action::danger('Select any User from dropdown!');
    }

    public function fields(NovaRequest $request)
    {
        $user = $request->user();

        if ($user && ! $user->hasRole('Administrator')) {
            return [
                Select::make('User', 'user_id')
                    ->options([
                        $user->id => $user->name,
                    ])
                    ->default($user->id)
                    ->help('Your profile is automatically selected for this action.'),
            ];
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
