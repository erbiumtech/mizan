<?php

namespace Tests\Feature;

use App\Modules\Campaigns\Filament\RelationManagers\ConsentsRelationManager;
use App\Modules\Campaigns\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Modules\Campaigns\Filament\Resources\Segments\Pages\CreateSegment;
use App\Modules\Campaigns\Filament\Resources\Segments\Pages\ListSegments;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Services\CampaignSender;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Crm\Filament\Resources\Leads\Pages\EditLead;
use App\Modules\Crm\Filament\Resources\LostReasons\Pages\CreateLostReason;
use App\Modules\Crm\Filament\Resources\LostReasons\Pages\EditLostReason;
use App\Modules\Crm\Filament\Resources\LostReasons\Pages\ListLostReasons;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Invoicing\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The screens the campaign flow needed and did not have: lost reasons for a deal's Lost action, segments
 * for a campaign's Send to, and consent recorded on the lead or customer it is about.
 */
class CampaignAudienceScreensTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['crm', 'campaigns', 'accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    public function test_a_lost_reason_is_added_on_its_own_screen_and_offered_to_the_lost_action(): void
    {
        Livewire::test(ListLostReasons::class)->assertOk();

        Livewire::test(CreateLostReason::class)
            ->fillForm(['name' => 'Price too high', 'is_active' => true, 'sort' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Price too high'], LostReason::query()->active()->pluck('name')->all());
    }

    public function test_a_lost_reason_a_deal_was_lost_for_cannot_be_deleted(): void
    {
        $reason = LostReason::create(['name' => 'No budget']);
        $pipeline = Pipeline::create(['name' => 'Sales', 'is_default' => true]);
        $stage = $pipeline->stages()->create(['name' => 'Lost', 'is_lost' => true, 'sort' => 1]);
        Opportunity::create([
            'title' => 'Lost deal', 'pipeline_id' => $pipeline->id, 'pipeline_stage_id' => $stage->id,
            'lead_id' => Lead::create(['company_name' => 'Gone Ltd'])->id, 'amount' => 100, 'lost_reason_id' => $reason->id,
        ]);

        Livewire::test(EditLostReason::class, ['record' => $reason->getKey()])->assertActionHidden('delete');

        $unused = LostReason::create(['name' => 'No response']);
        Livewire::test(EditLostReason::class, ['record' => $unused->getKey()])->assertActionVisible('delete');
    }

    public function test_a_segment_is_made_on_its_own_screen_and_resolves_its_rule(): void
    {
        $expo = LeadSource::create(['name' => 'Expo']);
        Lead::create(['company_name' => 'From the expo', 'lead_source_id' => $expo->id]);
        Lead::create(['company_name' => 'From elsewhere']);

        Livewire::test(CreateSegment::class)
            ->fillForm(['name' => 'Expo leads', 'is_active' => true, 'definition' => ['include_leads' => true, 'include_contacts' => false, 'lead_source_id' => $expo->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $segment = Segment::sole();
        $this->assertSame(['From the expo'], app(CampaignSender::class)->audienceFor($segment)->pluck('company_name')->all());

        Livewire::test(ListSegments::class)->assertOk()->assertCanSeeTableRecords([$segment]);
        Livewire::test(CreateCampaign::class)->assertFormFieldExists('segment_id', fn ($field): bool => array_key_exists($segment->id, $field->getOptions()));
    }

    public function test_consent_is_recorded_and_withdrawn_on_the_lead(): void
    {
        $lead = Lead::create(['company_name' => 'Willing Ltd', 'email' => 'w@example.test']);

        Livewire::test(ConsentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
            ->callTableAction('grant', data: ['channel' => Consent::CHANNEL_EMAIL, 'source' => 'Signed at the expo'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Consent::permits($lead, Consent::CHANNEL_EMAIL));
        $this->assertFalse(Consent::permits($lead, Consent::CHANNEL_WHATSAPP), 'consent is per channel');

        Livewire::test(ConsentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
            ->callTableAction('revoke', data: ['channel' => Consent::CHANNEL_EMAIL, 'source' => 'Replied STOP'])
            ->assertHasNoTableActionErrors();

        $this->assertFalse(Consent::permits($lead, Consent::CHANNEL_EMAIL));
        // Append-only: both rows stay, the latest decides.
        $this->assertSame(2, Consent::query()->where('subject_id', $lead->id)->count());
    }

    public function test_consent_is_recorded_on_the_customer(): void
    {
        $contact = Contact::create(['name' => 'A customer', 'kind' => Contact::KIND_CUSTOMER, 'email' => 'c@example.test']);

        Livewire::test(ConsentsRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->assertOk()
            ->callTableAction('grant', data: ['channel' => Consent::CHANNEL_WHATSAPP, 'source' => 'Asked on site'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Consent::permits($contact, Consent::CHANNEL_WHATSAPP));
    }

    public function test_the_consent_tab_is_absent_without_the_campaigns_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())->where('module', 'campaigns')->update(['enabled' => false]);
        modules()->flush();

        $this->assertFalse(ConsentsRelationManager::canViewForRecord(Lead::create(['company_name' => 'X']), EditLead::class));
    }
}
