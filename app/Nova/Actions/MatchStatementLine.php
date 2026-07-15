<?php

namespace App\Nova\Actions;

use App\Models\JournalEntryLine;
use App\Services\BankReconciliationService;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class MatchStatementLine extends Action
{
    public $name = 'Match';

    public function fields(NovaRequest $request)
    {
        $options = JournalEntryLine::query()
            ->whereNull('reconciled_at')
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true))
            ->whereDoesntHave('bankStatementLine')
            ->with('journalEntry:id,entry_number,entry_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (JournalEntryLine $l) => [
                $l->id => sprintf(
                    '%s · %s · %s',
                    $l->journalEntry->entry_number,
                    $l->journalEntry->entry_date->toDateString(),
                    number_format($l->signed_amount, 2)
                ),
            ])->all();

        return [
            Select::make('Ledger Line', 'ledger_line_id')
                ->options($options)
                ->searchable()
                ->rules('required')
                ->help('Choose the unreconciled posted ledger line this statement line corresponds to.'),
        ];
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(BankReconciliationService::class);
        $ledgerLine = JournalEntryLine::find($fields->ledger_line_id);

        if (! $ledgerLine) {
            return Action::danger('Selected ledger line not found.');
        }

        foreach ($models as $line) {
            try {
                $service->match($line, $ledgerLine);
            } catch (\Exception $e) {
                return Action::danger($e->getMessage());
            }
        }

        return Action::message('Statement line matched.');
    }
}
