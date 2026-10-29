<?php

namespace App\Modules\Lifecycle\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * Who is short of a required document, and of which — docs/saas-launch-plan.md §14.8.
 *
 * The other half of *Documents Expiring*. That one answers "what is about to lapse",
 * which is a question about documents the company holds; this answers "what have we
 * never had", which is a question about the ones it does not — and those are
 * invisible on a list of records, because a document nobody uploaded has no row.
 *
 * Empty until a company names what it requires. That is the right emptiness: a
 * compliance report against no policy is a page of green that means nothing.
 */
class DocumentCompliance extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Document Compliance';

    protected static ?int $navigationSort = 43;

    protected function reportActions(): array
    {
        return [
            HelpAction::make('document-compliance', 'Document Compliance: Help'),
        ];
    }
}
