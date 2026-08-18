<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\ContractItem;
use App\Modules\Core\Models\User;

/**
 * Who may maintain the item schedule.
 *
 * It rides on the contract's permissions (§18.2's Leave precedent — a schedule line is not a decision separate
 * from the contract it prices), but the *state* rules are its own and they are the ones that matter: a line may
 * be edited only while its contract is a draft, and a line written by an approved variation is never edited at
 * all. The second is the audit trail: that line is what the parties agreed the change was worth, and §9's
 * mechanism for changing it again is another variation.
 */
class ContractItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionContractView');
    }

    public function view(User $user, ContractItem $item): bool
    {
        return $user->can('ConstructionContractView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionContractUpdate');
    }

    public function update(User $user, ContractItem $item): bool
    {
        return $user->can('ConstructionContractUpdate')
            && ($item->contract?->isDraft() ?? false)
            && ! $item->isVariationLine();
    }

    public function delete(User $user, ContractItem $item): bool
    {
        return $this->update($user, $item);
    }
}
