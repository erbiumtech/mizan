<?php

namespace Tests\Feature;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\CustomFieldValue;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\CreateContact;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The deferred tail of custom fields: new field types, conditional visibility,
 * at-rest encryption, and table filters on custom columns.
 */
class CustomFieldExtrasTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_multi_select_round_trips_through_the_form(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        CustomField::create([
            'model_type' => Contact::class, 'code' => 'markets', 'name' => 'Markets',
            'type' => 'multi_select', 'options' => ['EU', 'US', 'APAC'],
        ]);

        Livewire::test(CreateContact::class)
            ->fillForm([
                'name' => 'Globex',
                'kind' => 'customer',
                'custom_fields' => ['markets' => ['EU', 'APAC']],
            ])
            ->call('create')
            ->assertHasNoErrors();

        $contact = Contact::where('name', 'Globex')->firstOrFail();
        $this->assertSame(['EU', 'APAC'], $contact->customFieldsData()['markets']);
    }

    public function test_conditional_visibility_config_persists(): void
    {
        CustomField::create([
            'model_type' => Contact::class, 'code' => 'is_reseller', 'name' => 'Reseller', 'type' => 'boolean',
        ]);

        $dependent = CustomField::create([
            'model_type' => Contact::class, 'code' => 'reseller_id', 'name' => 'Reseller ID', 'type' => 'text',
            'visible_when_field' => 'is_reseller', 'visible_when_value' => '1',
        ])->fresh();

        $this->assertSame('is_reseller', $dependent->visible_when_field);
        $this->assertSame('1', $dependent->visible_when_value);

        // Both fields still build as form components (the visibility closure is deferred).
        $this->assertCount(2, CustomFieldsSchema::form(Contact::class));
    }

    public function test_encrypted_value_is_ciphertext_at_rest_and_readable(): void
    {
        CustomField::create([
            'model_type' => Contact::class, 'code' => 'bank_iban', 'name' => 'IBAN',
            'type' => 'text', 'is_encrypted' => true,
        ]);

        $contact = Contact::create(['name' => 'Acme', 'kind' => 'customer', 'is_active' => true]);
        $contact->saveCustomFields(['bank_iban' => 'CH93 0076 2011 6238 5295 7']);

        $raw = (string) CustomFieldValue::query()->firstOrFail()->getRawOriginal('value');
        $this->assertStringNotContainsString('6238', $raw);

        $this->assertSame('CH93 0076 2011 6238 5295 7', $contact->fresh()->customFieldsData()['bank_iban']);
    }

    public function test_select_table_filter_narrows_results(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        CustomField::create([
            'model_type' => Contact::class, 'code' => 'tier', 'name' => 'Tier',
            'type' => 'select', 'options' => ['Gold', 'Silver'],
        ]);

        $gold = Contact::create(['name' => 'Gold Co', 'kind' => 'customer', 'is_active' => true]);
        $gold->saveCustomFields(['tier' => 'Gold']);

        $silver = Contact::create(['name' => 'Silver Co', 'kind' => 'customer', 'is_active' => true]);
        $silver->saveCustomFields(['tier' => 'Silver']);

        Livewire::test(ListContacts::class)
            ->filterTable('cf_tier', 'Gold')
            ->assertCanSeeTableRecords([$gold])
            ->assertCanNotSeeTableRecords([$silver]);
    }

    public function test_encrypted_fields_get_no_table_filter(): void
    {
        CustomField::create([
            'model_type' => Contact::class, 'code' => 'secret_tier', 'name' => 'Secret Tier',
            'type' => 'select', 'options' => ['A', 'B'], 'is_encrypted' => true,
        ]);

        $this->assertSame([], CustomFieldsSchema::tableFilters(Contact::class));
    }
}
