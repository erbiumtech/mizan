<?php

namespace App\Modules\Core\Filament\Pages\Tenancy;

use App\Multitenancy\CompanyProvisioner;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register company';
    }

    /**
     * Super admins only — see User::canCreateCompanies(). Filament aborts the
     * route on this too, so the page is gone, not merely hidden from the menu.
     */
    public static function canView(): bool
    {
        return auth()->user()?->canCreateCompanies() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Company name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    protected function handleRegistration(array $data): Model
    {
        return app(CompanyProvisioner::class)->provision(
            name: $data['name'],
            creator: auth()->user(),
        );
    }
}
