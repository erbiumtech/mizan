<?php

namespace App\Modules\Employees\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Employees\Models\Announcement;
use App\Modules\Employees\Models\Employee;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * What the company is telling you — docs/saas-launch-plan.md §7.6. Current
 * announcements aimed at the person signed in, pinned ones first.
 */
class AnnouncementsOverview extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.announcements-overview';

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    // HEADLINE + 5: above the bands because an announcement is read once and then not
    // looked for, and below HealthOverview at +4, which is about the system being up.
    protected static ?int $sort = DashboardWidgets::HEADLINE + 5;

    protected static bool $isLazy = true;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (
            Employee::query()->where('user_id', $user->getKey())->exists()
            || $user->can('AnnouncementView')
        );
    }

    /** @return Collection<int, Announcement> */
    public function announcements(): Collection
    {
        return Announcement::query()
            ->current()
            ->forUser(auth()->user())
            ->limit(5)
            ->get();
    }
}
