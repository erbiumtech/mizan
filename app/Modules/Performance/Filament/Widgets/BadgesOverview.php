<?php

namespace App\Modules\Performance\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Services\Badges;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\Widget;

/** My badges, earned and locked — docs/saas-launch-plan.md §8.4. */
class BadgesOverview extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.badges-overview';

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    // +6 rather than +5: the tax calculator holds +5, and two widgets on the same sort
    // fall back to discovery order, which is what the band numbers exist to replace.
    protected static ?int $sort = DashboardWidgets::PEOPLE + 6;

    protected static bool $isLazy = true;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::moduleIsAvailable() && static::employee() !== null;
    }

    public static function employee(): ?Employee
    {
        return auth()->check() ? Employee::query()->where('user_id', auth()->id())->first() : null;
    }

    /** @return array{earned: \Illuminate\Support\Collection, locked: \Illuminate\Support\Collection} */
    public function badges(): array
    {
        $employee = static::employee();
        $badges = app(Badges::class);

        return $employee
            ? ['earned' => $badges->earnedBy($employee), 'locked' => $badges->lockedFor($employee)]
            : ['earned' => collect(), 'locked' => collect()];
    }
}
