<?php

namespace App\Modules\Lifecycle\Services;

use App\Modules\Advances\Models\Advance;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\ClearanceItem;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Modules\Lifecycle\Models\Separation;
use App\Support\Contracts\ProvidentFundBalance;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Leaving, as a record with a clearance list — docs/saas-launch-plan.md §14.5. Auto items
 * come from the records (assets in hand, an advance outstanding, a fund balance); manual
 * ones from the exit checklist template or by hand; each is cleared or waived.
 */
class Separations
{
    public function __construct(private readonly ProvidentFundBalance $providentFund) {}

    public function open(Employee $employee, string $type, string|Carbon $lastWorkingDay, ?string $noticeOn = null, ?string $reason = null): Separation
    {
        if (Separation::query()->open()->where('employee_id', $employee->getKey())->exists()) {
            throw new InvalidArgumentException("{$employee->display_label} already has an open separation.");
        }

        if (! isset(Separation::TYPES[$type])) {
            throw new InvalidArgumentException("\"{$type}\" is not a kind of separation.");
        }

        $separation = Separation::create([
            'employee_id' => $employee->getKey(),
            'type' => $type,
            'notice_on' => $noticeOn,
            'last_working_day' => Carbon::parse($lastWorkingDay)->toDateString(),
            'reason' => $reason,
        ]);

        // The date on the record at once; the status follows after the last day — the person still works until then.
        $employee->forceFill(['left_on' => $separation->last_working_day->toDateString(), 'leaving_reason' => $reason ?? $separation->typeLabel()])->save();

        $this->buildClearance($separation);

        return $separation->refresh();
    }

    public function buildClearance(Separation $separation): void
    {
        $employee = $separation->employee;
        $sort = 0;

        foreach (IssuedAsset::query()->where('employee_id', $employee->getKey())->whereNull('returned_on')->get() as $asset) {
            $separation->items()->create(['title' => 'Return '.IssuedAsset::kindLabel((string) $asset->asset_kind).($asset->description ? ': '.$asset->description : ''), 'kind' => ClearanceItem::KIND_AUTO, 'source' => 'assets', 'amount' => $asset->value, 'sort' => $sort++]);
        }

        if (modules()->enabled('advances')) {
            foreach (Advance::query()->whereIn('status', [Advance::STATUS_ACTIVE, Advance::STATUS_PAUSED])->where('employee_id', $employee->getKey())->get() as $advance) {
                if ($advance->remainingAmount() > 0) {
                    $separation->items()->create(['title' => 'Recover the outstanding advance', 'kind' => ClearanceItem::KIND_AUTO, 'source' => 'advances', 'amount' => $advance->remainingAmount(), 'sort' => $sort++]);
                }
            }
        }

        $fund = $this->providentFund->balanceFor($employee->getKey(), $separation->last_working_day);

        if ($fund > 0) {
            $separation->items()->create(['title' => 'Settle the provident fund balance', 'kind' => ClearanceItem::KIND_AUTO, 'source' => 'provident_fund', 'amount' => $fund, 'sort' => $sort++]);
        }

        $template = ChecklistTemplate::query()->active()->ofKind(ChecklistTemplate::KIND_EXIT)->with('items')->first();

        foreach ($template?->items ?? [] as $item) {
            $separation->items()->create(['title' => $item->title, 'kind' => ClearanceItem::KIND_MANUAL, 'source' => 'checklist', 'sort' => $sort++]);
        }
    }

    public function clear(ClearanceItem $item, User $by, ?string $note = null): ClearanceItem
    {
        $item->update(['cleared_at' => now(), 'cleared_by' => $by->getKey(), 'waived_at' => null, 'waived_by' => null, 'waive_reason' => null, 'note' => $note ?? $item->note]);

        return $item->refresh();
    }

    public function waive(ClearanceItem $item, User $by, string $reason): ClearanceItem
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Waiving an item needs a reason.');
        }

        $item->update(['waived_at' => now(), 'waived_by' => $by->getKey(), 'waive_reason' => trim($reason)]);

        return $item->refresh();
    }

    /** After the last working day: the status the type leaves, and the record off the books. */
    public function closeDue(?Carbon $today = null): int
    {
        $today = ($today ?? now())->startOfDay();
        $closed = 0;

        foreach (Separation::query()->open()->whereDate('last_working_day', '<', $today->toDateString())->with('employee')->get() as $separation) {
            $separation->employee?->forceFill(['status' => Separation::STATUS_AFTER[$separation->type] ?? Employee::STATUS_RESIGNED, 'left_on' => $separation->last_working_day->toDateString()])->save();
            $separation->update(['status' => Separation::STATUS_CLOSED, 'closed_on' => $today->toDateString()]);
            $closed++;
        }

        return $closed;
    }

    /** Whether a leaver may still sign in — docs/saas-launch-plan.md §14.5: a configurable grace after the last day. */
    public static function loginStillOpen(Employee $employee, ?Carbon $today = null): bool
    {
        if ($employee->left_on === null) {
            return true;
        }

        $grace = (int) setting('lifecycle.login_grace_days', config('lifecycle.login_grace_days', 7));

        return ($today ?? now())->startOfDay()->lte($employee->left_on->copy()->addDays($grace));
    }
}
