<?php

namespace App\Modules\Core\Jobs;

use App\Backup\TenantBackup;
use App\Modules\Core\Models\Company;
use App\Notifications\RecordChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

/**
 * A backup somebody asked for — docs/saas-launch-plan.md §14.8.
 *
 * The same dump the nightly `backup:tenants` runs, for one company, on demand: what
 * an administrator wants before a bulk import or a payroll run they are nervous
 * about. Queued, because a dump of a real database is not a thing to do inside a
 * request.
 *
 * `NotTenantAware` for the reason {@see ExportTenant} is: it makes its own company
 * current through the backup connection, and a tenant already current would be the
 * wrong one half the time.
 *
 * One try. A failed dump is reported and left alone rather than retried — a retry
 * that also fails has only produced two half-archives and two alarms.
 */
class BackUpTenant implements NotTenantAware, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $companyId, public int $userId) {}

    public function handle(TenantBackup $backups): void
    {
        $company = Company::findOrFail($this->companyId);

        if ($backups->run($company) !== 0) {
            throw new RuntimeException('The backup command reported a failure. The log has what it said.');
        }

        $this->tell($company, 'Backup finished', 'The snapshot is on the Subscription page.');
    }

    public function failed(?Throwable $exception): void
    {
        $company = Company::find($this->companyId);

        if ($company !== null) {
            $this->tell($company, 'The backup failed', $exception?->getMessage() ?? 'No reason was recorded.');
        }
    }

    private function tell(Company $company, string $title, string $body): void
    {
        $user = $company->users()->whereKey($this->userId)->first();

        if ($user !== null) {
            Notification::send($user, new RecordChanged($title, $body));
        }
    }
}
