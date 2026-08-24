<?php

namespace App\Modules\Lifecycle\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Company kit still in somebody's hands.
 *
 * `docs/reports-expansion-plan.md` Phase 3.7: "`issued_assets` not returned, by employee, with value; ties
 * to the asset register and to settlement recovery."
 *
 * **Both ties are real.** The value column is what `FinalSettlementBuilder::unreturnedAssets()` would charge
 * — the same sum over the same scope — so the total is the recovery rather than an estimate of it. And
 * `fixed_asset_id` reaches the asset register, which is where the sharper finding lives: a *disposed* fixed
 * asset that somebody is still holding is kit written off while it was out of the building.
 *
 * Until now this was only visible one employee at a time, on their record. Nothing asked the company-wide
 * question, and nothing noticed that somebody who left in March still has a laptop.
 */
class AssetsInHand extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $title = "Assets in Employees' Hands";

    protected static ?int $navigationSort = 44;

    protected function getHeaderActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('assets-in-hand', "Assets in Employees' Hands: Help"),
        ];
    }
}
