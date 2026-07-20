<?php

namespace App\Nova\Actions;

use App\Services\BankReconciliationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class ImportStatementLines extends Action
{
    public $name = 'Import Lines (CSV)';

    public function fields(NovaRequest $request)
    {
        return [
            Textarea::make('CSV', 'csv')
                ->rules('required')
                ->help('One row per line: transaction_date,description,reference,amount (amount signed; negative for money out).'),
        ];
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(BankReconciliationService::class);
        $rows = $this->parseCsv($fields->csv);

        if ($rows === []) {
            return Action::danger('No valid rows found.');
        }

        foreach ($models as $statement) {
            try {
                $service->import($rows, $statement);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message(count($rows) . ' line(s) imported.');
    }

    protected function parseCsv(string $csv): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($csv)) as $raw) {
            $raw = trim($raw);

            if ($raw === '') {
                continue;
            }

            $cols = array_map('trim', str_getcsv($raw));

            if (strtolower($cols[0] ?? '') === 'transaction_date') {
                continue; // header row
            }

            $rows[] = [
                'transaction_date' => $cols[0] ?? null,
                'description' => $cols[1] ?? null,
                'reference' => $cols[2] ?? null,
                'amount' => $cols[3] ?? null,
            ];
        }

        return $rows;
    }
}
