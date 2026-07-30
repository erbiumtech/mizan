<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use Illuminate\Console\Command;

class CreateCompanyCommand extends Command
{
    protected $signature = 'companies:create
        {name : The display name of the company}
        {--slug= : Optional URL slug (auto-generated from the name if omitted)}
        {--owner= : Email of the user to attach as Administrator}';

    protected $description = 'Provision a new company: create and migrate its tenant database, seed baseline data, and attach an owner.';

    public function handle(CompanyProvisioner $provisioner): int
    {
        $creator = null;

        if ($ownerEmail = $this->option('owner')) {
            $creator = User::where('email', $ownerEmail)->first();

            if (! $creator) {
                $this->error("No user found with email [{$ownerEmail}].");

                return self::FAILURE;
            }
        }

        try {
            $company = $provisioner->provision(
                name: $this->argument('name'),
                slug: $this->option('slug'),
                creator: $creator,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Company [{$company->name}] provisioned at /admin/{$company->slug}.");

        return self::SUCCESS;
    }
}
