<?php

namespace Tests\Feature;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Banks\BankResource;
use App\Modules\Accounting\Filament\Resources\Banks\Pages\CreateBank;
use App\Modules\Accounting\Filament\Resources\Banks\Pages\EditBank;
use App\Modules\Accounting\Models\Bank;
use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use ReflectionClass;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class CrudRedirectsToListingTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /**
     * Every create/edit page in the panel must send the user back to the
     * listing after a save. Guards against a new resource being generated
     * without the trait.
     */
    public function test_every_create_and_edit_page_redirects_to_the_index(): void
    {
        $missing = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            foreach ($resource::getPages() as $key => $registration) {
                $page = $registration->getPage();

                if (! is_subclass_of($page, CreateRecord::class) && ! is_subclass_of($page, EditRecord::class)) {
                    continue;
                }

                $usesTrait = in_array(RedirectsToIndex::class, $this->traitsOf($page), true);
                $overrides = (new ReflectionClass($page))->getMethod('getRedirectUrl')->class === $page;

                if (! $usesTrait && ! $overrides) {
                    $missing[] = class_basename($resource)." [{$key}] → ".$page;
                }
            }
        }

        if ($missing) {
            $this->fail(
                "These CRUD pages do not redirect to their listing after save:\n - "
                .implode("\n - ", $missing)
            );
        }

        $this->addToAssertionCount(1);
    }

    public function test_creating_a_record_redirects_to_the_listing(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateBank::class)
            ->fillForm([
                'bank_name' => 'Test Bank',
                'bank_code' => 'TSTB',
                'bank_short_code' => 'TST',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(BankResource::getUrl('index'));

        $this->assertDatabaseHas('banks', ['bank_name' => 'Test Bank']);
    }

    public function test_saving_an_edit_redirects_to_the_listing(): void
    {
        $this->actingAsAdmin();

        $bank = Bank::create([
            'bank_name' => 'Before',
            'bank_code' => 'BFOR',
            'bank_short_code' => 'BFR',
        ]);

        Livewire::test(EditBank::class, ['record' => $bank->getKey()])
            ->fillForm(['bank_name' => 'After'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(BankResource::getUrl('index'));

        $this->assertSame('After', $bank->refresh()->bank_name);
    }

    protected function actingAsAdmin(): void
    {
        Gate::before(fn () => true);

        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();
    }

    /** @return list<class-string> every trait on the class and its parents */
    protected function traitsOf(string $class): array
    {
        $traits = [];

        for ($c = $class; $c !== false; $c = get_parent_class($c)) {
            $traits = array_merge($traits, class_uses($c) ?: []);
        }

        return array_values($traits);
    }
}
