<?php

namespace App\Filament\Concerns;

/**
 * Send the user back to the resource's listing page after a successful save.
 *
 * Filament's defaults keep you on the record: creating lands on the new
 * record's edit/view page, and saving an edit stays put. Every CRUD page in
 * this panel uses this trait so "Save" always returns to the list.
 *
 * Note: "Create & create another" is unaffected — Filament skips the redirect
 * for that action and re-opens a blank form.
 */
trait RedirectsToIndex
{
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
