<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Construction\Models\NamingConvention;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages\EditDailyLog;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\DeliveriesRelationManager;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\PhotosRelationManager;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogDelivery;
use App\Modules\ConstructionField\Models\DailyLogPhoto;
use App\Modules\ConstructionField\Services\DailyLogService;
use App\Modules\ConstructionField\Services\SitePhotoPromotion;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The diary's deliveries and photographs — §16.1, Phase 9c.
 *
 * **Two decisions are on trial here, and both are about not having two sources for one number.**
 *
 * *The delivery is the docket, not the valuation.* §16.1's list of what it carries has no rate and no amount in it, and
 * that absence is what keeps it out of the way of §5's priced goods receipt. What this table adds instead is the
 * question the priced receipt cannot answer: **which dockets accounts have never seen.**
 *
 * *`is_materials_on_site` corroborates Phase 8c's stock figure and does not replace it.* The reason is arithmetic: a
 * diary flag never comes off when material is built in, so a total of flagged dockets overstates what is on site by
 * everything already consumed and the error grows monthly. Stock's `remaining_quantity` goes down on issue, so stock is
 * the authority — and where there is no stock ledger the dockets are the whole of the evidence, which a certificate has
 * to say rather than showing nothing.
 *
 * The photographs carry §16.1's other rule: **they are not register containers**, because thirty thousand of them
 * would bury the drawings the register exists for — and the handful that are as-built evidence are promoted into it,
 * gated on the register's own permission rather than the diary's.
 */
class ConstructionSiteDeliveryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private DailyLogService $logs;

    private DailyLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'delivery@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->logs = app(DailyLogService::class);
        $this->log = $this->logs->open($this->job, '2026-08-20');
    }

    /** @param array<string, mixed> $attributes */
    private function delivery(array $attributes = [], ?DailyLog $log = null): DailyLogDelivery
    {
        return $this->logs->addDelivery($log ?? $this->log, array_merge([
            'docket_number' => 'DN-8841',
            'description' => '20 tonnes of 20mm aggregate',
            'quantity' => 20,
            'unit_of_measure' => 't',
            'supplier_label' => 'Karachi Aggregates',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function photo(array $attributes = []): DailyLogPhoto
    {
        return $this->logs->addPhoto($this->log, array_merge([
            'caption' => 'Level 4 slab reinforcement, east bay',
            'subject' => DailyLogPhoto::SUBJECT_CONCEALED_WORK,
            'file_path' => 'construction/site-photos/slab-east.jpg',
            'file_name' => 'slab-east.jpg',
            'file_mime' => 'image/jpeg',
            'file_size' => 2_400_000,
            'file_hash' => str_repeat('a', 64),
        ], $attributes));
    }

    private function switchOff(string ...$modules): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', $modules)
            ->update(['licensed' => false, 'enabled' => false]);

        modules()->flush();
    }

    // ------------------------------------------------------------------ the docket, not the valuation

    /** **No rate, no amount.** §16.1's delivery list has neither, and that is what keeps it out of §5's way. */
    public function test_a_delivery_carries_no_money(): void
    {
        $delivery = $this->delivery();

        foreach (['unit_rate', 'amount', 'cost_code_id'] as $column) {
            $this->assertArrayNotHasKey(
                $column,
                $delivery->getAttributes(),
                'A site docket that priced itself would be a second answer to what a delivery cost.'
            );
        }

        $this->assertSame('DN-8841', $delivery->docket_number);
        $this->assertSame('Karachi Aggregates', $delivery->supplierName());
    }

    /** A delivery with no docket is still recorded, and the gap is what shows. */
    public function test_a_load_with_no_paperwork_is_still_recorded(): void
    {
        $delivery = $this->delivery(['docket_number' => null]);

        $this->assertTrue($delivery->hasNoDocket());
        $this->assertStringStartsWith('No docket', $delivery->displayName());
    }

    public function test_a_delivery_has_to_say_what_arrived(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('what arrived');

        $this->delivery(['description' => '   ']);
    }

    /** Accepted with damage is the middle value, and the fact that disappears without it. */
    public function test_the_three_conditions_are_kept_apart(): void
    {
        $damaged = $this->delivery([
            'condition' => DailyLogDelivery::CONDITION_ACCEPTED_WITH_DAMAGE,
            'condition_notes' => 'Two bags split. Taken because the pour was booked.',
        ]);
        $rejected = $this->delivery(['condition' => DailyLogDelivery::CONDITION_REJECTED]);

        $this->assertTrue($damaged->wasAcceptedWithDamage());
        $this->assertFalse($damaged->wasRejected());
        $this->assertTrue($rejected->wasRejected());
        $this->assertStringContainsString('pour was booked', $damaged->condition_notes);
    }

    /** §16.1's lock reaches this child too — the delivery register of an approved day is evidence. */
    public function test_an_approved_day_takes_no_deliveries(): void
    {
        $this->logs->approve($this->log);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be added to');

        $this->delivery();
    }

    // ------------------------------------------------------------------ the dockets accounts never saw

    /**
     * **The exposure: material the job received that the cost ledger has never heard about.**
     *
     * Cost understated, margin overstated, and no error anywhere — the procurement counterpart of Phase 9b's
     * unnotified event.
     */
    public function test_a_docket_with_no_priced_receipt_behind_it_is_the_exposure(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction_costing'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $unreceipted = $this->delivery(['docket_number' => 'DN-1']);
        $this->delivery(['docket_number' => 'DN-2', 'goods_receipt_id' => 4242]);
        // A rejected load went back, so nobody should be receipting it and its absence is not an exposure.
        $this->delivery(['docket_number' => 'DN-3', 'condition' => DailyLogDelivery::CONDITION_REJECTED]);

        $exposed = $this->logs->unreceiptedDeliveries($this->job);

        $this->assertCount(1, $exposed);
        $this->assertSame($unreceipted->getKey(), $exposed->first()->getKey());
    }

    /**
     * **Empty without the cost module, deliberately.**
     *
     * With no cost ledger there are no goods receipts, so every docket would be listed and the report would be the
     * register itself — a control that fires on everything is one people learn to click through.
     */
    public function test_the_exposure_report_is_silent_where_there_is_no_cost_ledger(): void
    {
        $this->switchOff('construction_costing');

        $this->delivery();

        $this->assertCount(0, $this->logs->unreceiptedDeliveries($this->job));
    }

    public function test_a_linked_docket_reports_as_receipted(): void
    {
        $delivery = $this->delivery(['goods_receipt_id' => 77]);

        $this->assertTrue($delivery->isReceipted());
        $this->assertCount(0, DailyLogDelivery::query()->unreceipted()->get());
    }

    // ------------------------------------------------------------------ materials on site

    /**
     * **The flag is corroboration, and the arithmetic is why.**
     *
     * A diary flag never comes off when material is built in. Nothing here totals them, and this asserts the shape of
     * what is returned: dockets and their days, not a figure that could be mistaken for the stock one.
     */
    public function test_flagged_dockets_are_returned_as_dockets_and_not_as_a_total(): void
    {
        $this->delivery(['docket_number' => 'DN-1', 'is_materials_on_site' => true]);
        $this->delivery(['docket_number' => 'DN-2', 'is_materials_on_site' => false]);
        $this->delivery([
            'docket_number' => 'DN-3',
            'is_materials_on_site' => true,
            'condition' => DailyLogDelivery::CONDITION_REJECTED,
        ]);

        $onSite = $this->logs->materialsOnSiteDeliveries($this->job);

        $this->assertCount(1, $onSite, 'consumed and rejected are both excluded');
        $this->assertSame('DN-1', $onSite->first()->docket_number);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $onSite);
    }

    /** The same question of one day, off the loaded relation. */
    public function test_a_day_can_be_asked_what_it_left_standing(): void
    {
        $this->delivery(['is_materials_on_site' => true]);
        $this->delivery(['docket_number' => 'DN-2']);

        $this->assertCount(1, $this->log->refresh()->load('deliveries')->materialsOnSiteDeliveries());
    }

    /**
     * **It survives the cost module being absent, which is the case it exists for.**
     *
     * Where there is no stock ledger these dockets are the whole of the evidence for a materials-on-site claim.
     */
    public function test_the_site_record_works_with_no_cost_ledger_at_all(): void
    {
        $this->switchOff('construction_costing', 'inventory', 'accounting');

        $this->delivery(['is_materials_on_site' => true]);

        $this->assertCount(1, $this->logs->materialsOnSiteDeliveries($this->job));
    }

    // ------------------------------------------------------------------ photographs

    public function test_a_photograph_needs_a_file_and_a_caption(): void
    {
        try {
            $this->photo(['file_path' => null]);
            $this->fail('A photograph with no file should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a file', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a caption');

        $this->photo(['caption' => ' ']);
    }

    /** Dated the day the diary is for, not the day somebody uploaded it. */
    public function test_a_photograph_is_dated_from_the_diary(): void
    {
        $this->assertSame('2026-08-20', $this->photo()->taken_at->toDateString());
    }

    /** Concealed work and as-built are the evidential subjects — the ones somebody comes looking for. */
    public function test_concealed_work_is_flagged_as_evidence_that_is_not_in_the_register(): void
    {
        $concealed = $this->photo();
        $progress = $this->photo(['caption' => 'Crane erection', 'subject' => DailyLogPhoto::SUBJECT_PROGRESS]);

        $this->assertTrue($concealed->isEvidential());
        $this->assertTrue($concealed->needsPromoting());
        $this->assertFalse($progress->isEvidential());
        $this->assertFalse($progress->needsPromoting());

        $this->assertCount(1, $this->logs->photosNeedingPromotion($this->job));
        $this->assertCount(1, $this->log->refresh()->load('photos')->photosNeedingPromotion());
    }

    public function test_a_photograph_can_say_where_it_was_taken(): void
    {
        $level = Location::create([
            'job_id' => $this->job->getKey(), 'code' => 'L4', 'name' => 'Level 4', 'type' => Location::TYPE_LEVEL,
        ]);

        $photo = $this->photo(['location_id' => $level->getKey()]);

        $this->assertSame('Level 4', $photo->refresh()->location->name);
    }

    // ------------------------------------------------------------------ promotion to the register

    /**
     * **Promotion puts the photograph in the register and does not publish it.**
     *
     * §15's approval gate is a separate act by a separate permission, and a promotion that published would be a way
     * around it. The container is a `photograph`, carries the same file, and records where it came from.
     */
    public function test_promoting_creates_a_work_in_progress_container_with_the_same_file(): void
    {
        $photo = $this->photo();

        $document = app(SitePhotoPromotion::class)->promote($photo);

        $this->assertSame(Document::STATE_WIP, $document->cde_state, 'promotion is not publication');
        $this->assertSame('photograph', $document->document_type);
        $this->assertSame('J-1-PH-0001', $document->information_container_id);
        $this->assertStringContainsString('Promoted from the site diary', $document->description);
        $this->assertStringContainsString('Concealed work', $document->description);

        $revision = $document->currentRevision;
        $this->assertNotNull($revision);
        $this->assertSame('A', $revision->revision);
        // One file, two rows: the register holds the same image an adjudicator was shown, not a re-encoding of it.
        $this->assertSame($photo->file_path, $revision->file_path);
        $this->assertSame($photo->file_hash, $revision->file_hash);
        $this->assertSame('2026-08-20', $revision->issued_on->toDateString());

        $photo->refresh();
        $this->assertTrue($photo->isPromoted());
        $this->assertFalse($photo->needsPromoting());
        $this->assertNotNull($photo->promoted_by);
    }

    public function test_promoting_twice_is_refused(): void
    {
        $photo = $this->photo();
        app(SitePhotoPromotion::class)->promote($photo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already in the register');

        app(SitePhotoPromotion::class)->promote($photo->refresh());
    }

    /** The identifiers do not collide, which is what the unique index on the register demands. */
    public function test_a_second_promotion_takes_the_next_number(): void
    {
        $first = app(SitePhotoPromotion::class)->promote($this->photo());
        $second = app(SitePhotoPromotion::class)->promote($this->photo(['caption' => 'West bay steel']));

        $this->assertSame('J-1-PH-0001', $first->information_container_id);
        $this->assertSame('J-1-PH-0002', $second->information_container_id);
    }

    /** Where the job has issued a naming convention, the container is named in it. */
    public function test_a_job_with_a_naming_convention_gets_an_identifier_in_it(): void
    {
        NamingConvention::create([
            'job_id' => $this->job->getKey(),
            'name' => 'Project standard',
            'separator' => '-',
            'field_order' => ['project_code', 'form_code', 'container_number'],
            'is_default' => true,
        ]);

        $document = app(SitePhotoPromotion::class)->promote($this->photo());

        $this->assertSame('J-1-PH-0001', $document->information_container_id);
        $this->assertNotNull($document->naming_convention_id);
    }

    /**
     * A project whose code list excludes `PH` gets the fallback rather than a refusal.
     *
     * The point of promoting evidence is that it ends up findable; losing that to a code-list mismatch would be the
     * tail wagging the dog.
     */
    public function test_a_code_list_without_a_photograph_code_falls_back_rather_than_refusing(): void
    {
        NamingConvention::create([
            'job_id' => $this->job->getKey(),
            'name' => 'Strict',
            'separator' => '-',
            'code_lists' => ['form_code' => ['DR', 'SP']],
            'is_default' => true,
        ]);

        $document = app(SitePhotoPromotion::class)->promote($this->photo());

        $this->assertSame('J-1-PH-0001', $document->information_container_id);
        $this->assertNull($document->naming_convention_id);
    }

    /**
     * **A promoted photograph cannot be deleted from the diary.**
     *
     * The register's revision points at the same file. A register listing a file nobody can produce is worse than
     * never having promoted it, because the register's whole value is that what it lists exists.
     */
    public function test_a_promoted_photograph_cannot_be_deleted(): void
    {
        $photo = $this->photo();
        app(SitePhotoPromotion::class)->promote($photo);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('in the document register');

        $photo->refresh()->delete();
    }

    public function test_an_unpromoted_photograph_can_be_deleted(): void
    {
        $photo = $this->photo(['subject' => DailyLogPhoto::SUBJECT_PROGRESS]);

        $this->assertTrue($photo->delete());
        $this->assertSame(0, DailyLogPhoto::query()->count());
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_deliveries_tab_shows_the_docket_and_what_accounts_have_not_seen(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction_costing'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $delivery = $this->delivery(['is_materials_on_site' => true]);

        Livewire::test(DeliveriesRelationManager::class, [
            'ownerRecord' => $this->log,
            'pageClass' => EditDailyLog::class,
        ])
            ->assertCanSeeTableRecords([$delivery])
            ->assertSee('DN-8841')
            ->assertSee('Karachi Aggregates')
            ->assertSee('not receipted');
    }

    public function test_the_deliveries_tab_records_a_docket(): void
    {
        Livewire::test(DeliveriesRelationManager::class, [
            'ownerRecord' => $this->log,
            'pageClass' => EditDailyLog::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'docket_number' => 'DN-9002',
                'description' => '40 bags OPC',
                'supplier_label' => 'Lucky Cement',
                'condition' => DailyLogDelivery::CONDITION_ACCEPTED,
                'is_materials_on_site' => true,
            ]);

        $delivery = DailyLogDelivery::query()->firstOrFail();

        $this->assertSame('DN-9002', $delivery->docket_number);
        $this->assertTrue($delivery->is_materials_on_site);
        $this->assertNotNull($delivery->received_by);
    }

    public function test_the_photos_tab_promotes_to_the_register(): void
    {
        $photo = $this->photo();

        Livewire::test(PhotosRelationManager::class, [
            'ownerRecord' => $this->log,
            'pageClass' => EditDailyLog::class,
        ])
            ->assertCanSeeTableRecords([$photo])
            ->assertSee('evidence, not in the register')
            ->callAction(TestAction::make('promote')->table($photo), [
                'title' => 'L4 slab reinforcement — east bay',
                'is_contractual' => true,
            ]);

        $document = Document::query()->firstOrFail();

        $this->assertSame('L4 slab reinforcement — east bay', $document->title);
        $this->assertTrue($document->is_contractual);
        $this->assertSame($document->getKey(), $photo->refresh()->promoted_document_id);
    }

    /** Both children work with only the spine and this module — §18. */
    public function test_the_two_children_work_with_only_the_spine_and_this_module(): void
    {
        $this->switchOff('construction_costing', 'construction_contracts', 'inventory', 'invoicing', 'accounting');

        $delivery = $this->delivery(['is_materials_on_site' => true, 'supplier_contact_id' => null]);
        $photo = $this->photo();

        $this->assertSame('Karachi Aggregates', $delivery->supplierName());
        $this->assertCount(1, $this->logs->materialsOnSiteDeliveries($this->job));
        $this->assertNotNull(app(SitePhotoPromotion::class)->promote($photo));
    }
}
