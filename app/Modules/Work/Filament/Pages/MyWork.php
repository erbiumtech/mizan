<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Services\Boards;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * One person's work, across every project — docs/project-management-plan.md §5.5.
 *
 * **A board nobody has to make**, and for most people the first screen they open. It extends the
 * project board because a drop behaves identically on both — the same WIP check, the same workflow
 * rules, the same refusal — and two implementations of that would drift.
 *
 * Filed in Home rather than under Projects: like the other Personal screens, it is about the person
 * looking at it rather than about the company. See `App\Support\NavigationDomains`.
 */
class MyWork extends Board
{
    use BelongsToModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.work.my-work';

    public function getTitle(): string|Htmlable
    {
        return __('My Work');
    }

    public static function getNavigationLabel(): string
    {
        return __('My Work');
    }

    /**
     * Its own help topic rather than the board's, inherited.
     *
     * The two screens answer different questions — "how is this project going" against "what am I
     * supposed to be doing" — and HelpCoverageTest asks for the call here anyway, which is the
     * right ask: a page whose help was written for a different page is help nobody reads twice.
     */
    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('my-work', 'My Work: Help'),
        ];
    }

    public function board(): ?WorkBoard
    {
        return auth()->user() === null ? null : app(Boards::class)->myWork(auth()->user());
    }

    /** Derived from who is looking, so there is nothing on it to filter by project. */
    public function filters(): array
    {
        return [];
    }

    public function unmapped(): array
    {
        return [];
    }
}
