<?php

namespace App\Modules\Accounting\Filament\Resources\Loans\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Loans\LoanResource;
use App\Modules\Accounting\Services\LoanService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditLoan extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = LoanResource::class;

    /** What the schedule is worked out from. */
    private const TERMS = ['principal', 'annual_rate', 'term_months', 'starts_on'];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Terms are fixed once an instalment has reached the ledger.
     *
     * LoanPolicy::update() closes the form at that point, but an Administrator
     * passes every policy, and the save then failed inside generateSchedule()
     * with the record already written. Refused here, before anything is saved,
     * and only for the terms — a name, a note or the active flag still saves.
     */
    protected function beforeSave(): void
    {
        if ($this->record->paidCount() === 0
            || ! (clone $this->record)->fill(Arr::only($this->data, self::TERMS))->isDirty(self::TERMS)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('The terms of this loan can no longer change')
            ->body('Instalments have already been recorded against it, so its principal, rate, term and start date are fixed. The name, lender, notes and active flag can still be changed.')
            ->send();

        $this->halt();
    }

    /**
     * Changed terms mean a different schedule, so only changed terms rebuild it —
     * editing the notes of a loan with recorded instalments used to throw here.
     */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged(self::TERMS)) {
            app(LoanService::class)->generateSchedule($this->record);
        }
    }
}
