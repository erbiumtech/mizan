<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Support\Reporting\ModuleReportPage;
use BackedEnum;

/**
 * What the bank says against what the books say — `docs/reports-expansion-plan.md` Phase 2.6.
 *
 * The four lines a year-end file asks for, per bank account: the bank's closing balance, less the cheques it
 * has not paid yet, plus the deposits it has not credited yet, against the ledger. The matching screen shows
 * one statement's lines; nothing showed the position, and the position is what an auditor asks for.
 *
 * **It is a report about *open* statements, which is not what the plan expected.** `complete()` requires a
 * statement's closing balance to equal the ledger balance exactly, and an unpresented cheque makes those two
 * differ by definition — so a statement carrying one cannot be completed, and a completed one has nothing
 * left to reconcile. The rows worth reading are the open ones, and the note says so when it finds them.
 */
class BankReconciliationStatement extends ModuleReportPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $title = 'Bank Reconciliation Statement';

    protected static ?int $navigationSort = 15;

    protected function reportActions(): array
    {
        // Literal, in this file. HelpCoverageTest reads each page's own source, and the slug is per report.
        return [
            HelpAction::make('bank-reconciliation-statement', 'Bank Reconciliation Statement: Help'),
        ];
    }
}
