<?php

namespace App\Modules\Core\Filament\Resources\ActivityLogs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('activity-logs', 'Activity Log: Help'),
        ];
    }

    /**
     * On this list an entry opens in a modal, not on its own page.
     *
     * This is where the decision has to be made, and it took finding: `Action::getUrl()` returns
     * `$this->url ?? $livewire->getDefaultActionUrl($this)`, and the resource page's default hands a
     * ViewAction the view-page URL whenever the resource *has* a view page. So `->url(null)` on the action
     * changes nothing — null is indistinguishable from unset and the default still applies. Declining to
     * supply the default is the only thing that does.
     *
     * An audit entry is a few fields; opening a page to read them, then going back to the list, is three
     * navigations for one glance. The view page stays registered — global search links to it and a deep
     * link to one entry is worth keeping — it is just not where this list sends you.
     */
    public function getDefaultActionUrl(Action $action): ?string
    {
        if ($action instanceof ViewAction) {
            return null;
        }

        return parent::getDefaultActionUrl($action);
    }
}
