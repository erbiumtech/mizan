<?php

namespace App\Modules\Quotations\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Quotations\Services\QuotationService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Move sent quotes past their validity to `expired`.
 *
 * The status change is what makes an expired quote visibly expired in a list. Acceptance is
 * refused by DATE regardless of the status, so a quote that lapsed this morning cannot be
 * accepted this afternoon even before this has run — the sweep is for the record, not the rule.
 */
class ExpireQuotations extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'quotations:expire {--tenant=*} {--date= : Treat this date as today}';

    protected $description = 'Mark sent quotes whose validity has passed as expired';

    public function handle(QuotationService $quotations): int
    {
        if ($this->skipsDisabledModule('quotations')) {
            return self::SUCCESS;
        }

        $expired = $quotations->expireLapsed($this->option('date'));

        $this->info($expired === 0 ? 'No quotes have lapsed.' : "Expired {$expired} quote(s).");

        return self::SUCCESS;
    }
}
