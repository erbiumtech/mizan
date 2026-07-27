<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\User;
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

        return app(CompanyProvisioner::class)->provision(
            name: $data['name'],
            creator: $admin,
        );
    }
}
