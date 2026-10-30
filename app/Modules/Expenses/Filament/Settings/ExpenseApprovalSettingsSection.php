<?php

namespace App\Modules\Expenses\Filament\Settings;

use App\Modules\Expenses\Services\ExpenseClaimApprovalChain;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Components\Section;
use Spatie\Permission\Models\Role;

/** Who approves after the first approval — docs/saas-launch-plan.md §14.3. Empty is one step, as before. */
class ExpenseApprovalSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'expenses.approval-chain';
    }

    public function components(): array
    {
        return [
            Section::make('Expense claim approval')
                ->description('The first approval is anybody holding Approve expense claims who did not submit it. Name the roles that must approve after that, in order — "Manager, then HR" is the usual chain. Leave it empty for one step.')
                ->visible(fn (): bool => modules()->enabled('expenses'))
                ->schema([
                    TagsInput::make('expenses_approval_chain')
                        ->label(__('Then, in order'))
                        ->suggestions(fn (): array => Role::query()->orderBy('name')->pluck('name')->all())
                        ->placeholder('Add a role'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['expenses_approval_chain' => (array) setting(ExpenseClaimApprovalChain::SETTING_KEY, [])];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('expenses_approval_chain', $state)) {
            return;
        }

        app(TenantSettings::class)->set(ExpenseClaimApprovalChain::SETTING_KEY, array_values(array_filter(array_map('trim', (array) $state['expenses_approval_chain']))));
    }
}
