<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Accounting\Filament\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Crm\Filament\Resources\Leads\Pages\ListLeads;
use App\Modules\Crm\Models\Lead;
use App\Modules\Support\Filament\Resources\Tickets\Pages\ListTickets;
use App\Modules\Support\Models\Ticket;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * View sheets read an infolist — docs/side-sheets.md.
 *
 * A `SheetView` resource's view sheet shows the record's values, not its edit form disabled: entries
 * (`fi-in-entry`) and no form fields (`fi-fo-field`), option labels rather than keys, and relations by
 * name. Every list here holds two rows, so the list hydrates a collection and lazy-loading prevention
 * is live: a relation an entry reads without it being loaded would throw while the sheet renders.
 */
class RecordSheetInfolistTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Model::preventLazyLoading();

        $this->actingAs($this->makeUser('Administrator', 'infolists@test.local'));
        $this->setCurrentTenant();
    }

    public function test_an_account_sheet_reads_values_not_inputs(): void
    {
        $accounts = Account::query()->orderBy('code')->limit(2)->get();
        $this->assertCount(2, $accounts, 'the chart of accounts is seeded with the company');
        $account = $accounts->first();

        Livewire::test(ListAccounts::class)
            ->mountAction(TestAction::make('view')->table($account))
            ->assertMountedActionModalSeeHtml('fi-in-entry')
            ->assertMountedActionModalDontSeeHtml('fi-fo-field')
            ->assertMountedActionModalSee([$account->code, $account->name, __('Normal Balance')]);
    }

    public function test_a_fixed_asset_sheet_reads_its_account_by_name_and_its_method_by_label(): void
    {
        $account = Account::where('code', '1400')->firstOrFail();

        $assets = collect(['Site laptop', 'Survey drone'])->map(fn (string $name): FixedAsset => FixedAsset::create([
            'name' => $name,
            'account_id' => $account->id,
            'purchase_date' => '2026-07-01',
            'purchase_cost' => 360_000,
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 36,
            'salvage_value' => 0,
        ]));

        Livewire::test(ListFixedAssets::class)
            ->mountAction(TestAction::make('view')->table($assets->first()))
            ->assertMountedActionModalSeeHtml('fi-in-entry')
            ->assertMountedActionModalDontSeeHtml('fi-fo-field')
            ->assertMountedActionModalSee(['Site laptop', $account->name, __('Straight Line'), $assets->first()->asset_code]);
    }

    public function test_a_lead_sheet_reads_its_status_and_source_as_labels(): void
    {
        $leads = collect([
            ['company_name' => 'Karachi Textiles', 'status' => Lead::STATUS_WORKING, 'rating' => Lead::RATING_HOT],
            ['company_name' => 'Lahore Foods', 'status' => Lead::STATUS_NEW],
        ])->map(fn (array $attributes): Lead => Lead::create($attributes));

        Livewire::test(ListLeads::class)
            ->mountAction(TestAction::make('view')->table($leads->first()))
            ->assertMountedActionModalSeeHtml('fi-in-entry')
            ->assertMountedActionModalDontSeeHtml('fi-fo-field')
            ->assertMountedActionModalSee(['Karachi Textiles', __('Working'), __('Hot')]);
    }

    public function test_a_ticket_sheet_reads_its_number_status_and_priority(): void
    {
        $tickets = collect(['Printer jammed', 'Payslip missing'])->map(fn (string $subject): Ticket => Ticket::create([
            'subject' => $subject,
            'status' => Ticket::STATUS_OPEN,
            'priority' => 'high',
        ]));

        $ticket = $tickets->first()->fresh();

        Livewire::test(ListTickets::class)
            ->mountAction(TestAction::make('view')->table($ticket))
            ->assertMountedActionModalSeeHtml('fi-in-entry')
            ->assertMountedActionModalDontSeeHtml('fi-fo-field')
            ->assertMountedActionModalSee(['Printer jammed', (string) $ticket->number, __('Open'), __('High')]);
    }
}
