<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompany extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = CompanyResource::class;

    /**
     * Provision the company (tenant DB + baseline seed + per-company roles) and
     * attach the chosen user as its Administrator — instead of a plain insert.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $admin = User::findOrFail($data['admin_user_id']);

        $company = app(CompanyProvisioner::class)->provision(
            name: $data['name'],
            creator: $admin,
            // Passed through, not defaulted. The provisioner has accepted a type
            // since personal accounts were added and this call never sent one,
            // so every company created here came out a business whatever the
            // form said — and the only way to make a personal account was to
            // call the provisioner by hand.
            type: $data['type'] ?? Company::TYPE_BUSINESS,
            // Decides the starting licences and the baseline seeders. Null is a
            // real answer, not a missing one: the company then starts with Core
            // alone and is licensed by hand.
            profile: $data['profile'] ?? null,
        );

        // Set after provisioning rather than passed into it — these touch no
        // seeding (legal_entity only picks a tax engine later; the exemption
        // fields are reference data), so they are plain column writes, not part of
        // the tenant's construction. Blank legal_entity stays null, which reads as
        // the entity the type implies.
        $classification = array_filter(
            ['legal_entity', 'tax_exempt_ref', 'tax_exempt_approved_on'],
            fn (string $key): bool => ! empty($data[$key]),
        );

        if ($classification !== []) {
            $company->update(array_intersect_key($data, array_flip($classification)));
        }

        return $company;
    }
}
