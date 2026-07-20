<?php

namespace App\Nova\Metrics;

use App\Models\JournalEntry;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class PendingJournalEntries extends Value
{
    public $name = 'Journal Entries Awaiting Approval';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->result(JournalEntry::where('status', JournalEntry::STATUS_PENDING)->count())
            ->allowZeroResult()
            ->suffix('pending');
    }

    public function ranges(): array
    {
        return [];
    }

    public function uriKey(): string
    {
        return 'pending-journal-entries';
    }
}
