<?php

namespace App\Modules\Core\Filament\Resources\ReportSchedules\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Filament\Resources\ReportSchedules\ReportScheduleResource;
use App\Modules\Core\Models\SavedReportView;
use Filament\Resources\Pages\CreateRecord;

class CreateReportSchedule extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ReportScheduleResource::class;

    /**
     * Prefilled from the report somebody was looking at — `docs/reports-expansion-plan.md` Phase 8, item 1.
     *
     * The hub's *Schedule* button links here with the open report and its filters, because a schedule stores
     * "the same state the URL carries". Filled over the form's own defaults rather than instead of them: the
     * period, the timetable, the timezone and the format are still whatever the form says, and only what the
     * link actually carried is replaced.
     *
     * **The report key is checked against this person's catalogue**, which is the same check
     * `Reports::select()` applies and for the same reason: it arrives from a query string.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $key = request()->query('report_key');
        $state = array_intersect_key(
            (array) request()->query('state', []),
            array_flip(SavedReportView::FILTERS),
        );

        $prefill = array_filter([
            'report_key' => is_string($key) && array_key_exists($key, Reports::catalogue()) ? $key : null,
            'state' => $state === [] ? null : $state,
        ]);

        if ($prefill !== []) {
            /*
             * Over the state the defaults just produced, rather than `fillPartially()`.
             *
             * That method dots its paths, so a value that is itself an array — the report's filters — is
             * flattened to `state.month` and then dropped by a path list naming `state`. Refilling the whole
             * form with its current state plus the prefill keeps the defaults and carries the array intact.
             */
            $this->form->fill([...(array) $this->form->getRawState(), ...$prefill]);
        }
    }

    /**
     * The owner is whoever created it — `docs/reports-expansion-plan.md` Phase 8, item 2.
     *
     * Set here rather than offered as a field, because the owner is not a preference: it is the person whose
     * access decides what the rows are, and choosing somebody else would be a way to render a report with
     * their permissions. A schedule that should belong to somebody else is one they create.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
