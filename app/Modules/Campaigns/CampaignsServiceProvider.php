<?php

namespace App\Modules\Campaigns;

use App\Modules\Campaigns\Filament\Pages\CampaignPerformance;
use App\Modules\Campaigns\Filament\Pages\ConsentRegister;
use App\Modules\Campaigns\Filament\RelationManagers\ConsentsRelationManager;
use App\Modules\Campaigns\Filament\Settings\CampaignSendingSettingsSection;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignSend;
use App\Modules\Campaigns\Models\Consent;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Policies\CampaignPolicy;
use App\Modules\Campaigns\Policies\CampaignSendPolicy;
use App\Modules\Campaigns\Policies\ConsentPolicy;
use App\Modules\Campaigns\Policies\SegmentPolicy;
use App\Modules\Campaigns\Support\CampaignReports;
use App\Modules\Campaigns\Support\ConsentReports;
use App\Modules\Crm\Filament\Resources\Leads\LeadResource;
use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use App\Modules\Invoicing\Models\Contact;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportRenderers;
use App\Support\ResourceContributions;
use App\Support\SettingsSections;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CampaignsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Segment::class => SegmentPolicy::class,
        Campaign::class => CampaignPolicy::class,
        CampaignSend::class => CampaignSendPolicy::class,
        Consent::class => ConsentPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerReports();

        // Company Settings → Customers: how fast a campaign goes out.
        SettingsSections::register('campaigns.sending', CampaignSendingSettingsSection::class, 78, 'customers');

        $this->contributeConsent();
    }

    /**
     * Consent is recorded on the person — the lead and the customer — from here, so neither CRM nor
     * Invoicing names this module. The relation is resolved rather than declared for the same reason.
     */
    private function contributeConsent(): void
    {
        Lead::resolveRelationUsing('consents', fn (Lead $lead) => $lead->morphMany(Consent::class, 'subject'));
        ResourceContributions::addRelationManager(LeadResource::class, ConsentsRelationManager::class);

        // Customers live in Invoicing, which this module does not require — guarded as the sender is.
        if (class_exists(Contact::class)) {
            Contact::resolveRelationUsing('consents', fn (Contact $contact) => $contact->morphMany(Consent::class, 'subject'));
            ResourceContributions::addRelationManager(ContactResource::class, ConsentsRelationManager::class);
        }
    }

    /**
     * The consent register and campaign performance — `docs/reports-expansion-plan.md` Phases 3.10 and 3.11.
     *
     * Filed under *Sales & pipeline* rather than with the operational reports: the person who needs to know
     * whether a permission can be defended is whoever is about to run the campaign.
     *
     * Registered unconditionally. The page gates itself on `moduleIsAvailable()` and `Reports::sections()`
     * filters through `canAccess()`, so a company without the campaigns module sees no entry rather than a
     * report that fails when opened.
     */
    private function registerReports(): void
    {
        ReportCatalogue::register(
            'Sales & pipeline',
            ConsentRegister::class,
            'Who may be contacted on which channel, with the evidence behind each permission.',
        );

        ReportCatalogue::register(
            'Sales & pipeline',
            CampaignPerformance::class,
            'What each campaign reached, what it skipped for want of consent, and why sends failed.',
        );

        ReportRenderers::register(
            'ConsentRegister',
            fn (string $asOf): array => app(ConsentReports::class)->consentRegister($asOf),
        );
        ReportRenderers::register(
            'CampaignPerformance',
            fn (string $asOf): array => app(CampaignReports::class)->campaignPerformance($asOf),
        );
    }
}
