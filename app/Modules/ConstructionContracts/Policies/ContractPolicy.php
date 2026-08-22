<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\Core\Models\User;

/**
 * Who may read, price, execute and delete a contract.
 *
 * `update` answers "may this role edit at all"; whether *this* contract is still a draft is
 * `ContractService`'s. Keeping them apart matters for the same reason it does on a cost entry: a permission
 * grant must not be able to edit a schedule that certificates have already been measured against.
 *
 * **Deleting is refused once anything hangs off the contract**, which is stricter than the permission alone.
 * A contract with certificates issued against it is a contractual record somebody outside this company holds
 * a copy of; the way to end one is `terminated`, which leaves the record standing.
 */
class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionContractView');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('ConstructionContractView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionContractCreate');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('ConstructionContractUpdate') && $contract->isDraft();
    }

    /** Execution freezes the scheduled values every later certificate is measured against. */
    public function execute(User $user, Contract $contract): bool
    {
        return $user->can('ConstructionContractExecute') && $contract->isDraft();
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->can('ConstructionContractDelete')
            && $contract->isDraft()
            && $contract->subcontracts()->doesntExist();
    }
}
