<?php

namespace App\Modules\ConstructionContracts\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\RetentionService;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Reconcile the retention ledger against the certificates — `docs/construction-management-plan.md` §11.
 *
 * **It notifies rather than throws, and the difference is deliberate.** A command that threw would stop running,
 * and a register nobody reconciles is exactly the state this exists to detect. §4.3 makes the same choice for the
 * cost ledger's reconciliation: report, never plug.
 *
 * The comparison is **held movements against the latest certificate's cumulative retention**, not the whole
 * balance: releases are supposed to make the balance differ from the certified figure, and comparing the balance
 * would produce a difference on every job that has ever released anything, which is a report people stop reading.
 */
class ReconcileRetention extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'construction:reconcile-retention
                            {--contract= : One contract, by number}
                            {--tenant=* : One or more tenants to run for}';

    protected $description = 'Compare the retention ledger with what the certificates say is held';

    public function handle(RetentionService $retention): int
    {
        if ($this->skipsDisabledModule('construction_contracts')) {
            return self::SUCCESS;
        }

        $contracts = Contract::query()
            ->whereNot('status', Contract::STATUS_DRAFT)
            ->when($this->option('contract'), fn ($q) => $q->where('contract_number', $this->option('contract')))
            ->with('job')
            ->get();

        if ($contracts->isEmpty()) {
            $this->line('No executed contracts to reconcile.');

            return self::SUCCESS;
        }

        $rows = [];
        $differences = 0;

        foreach ($contracts as $contract) {
            $result = $retention->reconcile($contract);

            if (! $result['explained']) {
                $differences++;
            }

            $rows[] = [
                $contract->job?->code,
                $contract->contract_number,
                number_format($result['held'], 2),
                number_format($result['certified'], 2),
                number_format($result['difference'], 2),
                number_format($result['balance'], 2),
                $result['explained'] ? 'agrees' : 'DIFFERS',
            ];
        }

        $this->table(['Job', 'Contract', 'Held (ledger)', 'Held (certificates)', 'Difference', 'Balance', ''], $rows);

        if ($differences === 0) {
            $this->info('The ledger and the certificates agree on every contract.');

            return self::SUCCESS;
        }

        // A warning and a success exit: the difference needs a person, and a failing exit code would take the
        // scheduler down with it and stop the next night's comparison.
        $this->warn(
            $differences.' contract'.($differences === 1 ? '' : 's').' where the retention ledger and the '
            .'certificates disagree. Each difference is a movement somebody wrote by hand, a voided certificate, '
            .'or a bug — and none of the three fixes itself.'
        );

        return self::SUCCESS;
    }
}
