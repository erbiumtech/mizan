<?php
namespace Tests\Feature;

use App\Filament\Pages\CompanySettings;
use App\Models\Setting;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

class TmpIpayProbeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    public function test_probe(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'ipay@test.local'));
        $this->setCurrentTenant();

        $c = Livewire::test(CompanySettings::class);
        $state = $c->get('data')['ipayments'] ?? null;
        fwrite(STDERR, "\nfilled state for ipayments:\n".var_export($state, true)."\n");

        $c->call('save');
        app(TenantSettings::class)->flush();

        $stored = Setting::where('key', 'ipayments')->value('value');
        fwrite(STDERR, "\nSTORED after save:\n".var_export($stored, true)."\n");
        fwrite(STDERR, "\nown_bank after save: ".var_export(setting('ipayments')['own_bank'] ?? null, true)."\n");

        $this->assertTrue(true);
    }
}
