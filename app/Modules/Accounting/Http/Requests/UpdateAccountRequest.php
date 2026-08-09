<?php

namespace App\Modules\Accounting\Http\Requests;

use App\Modules\Accounting\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($account->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['boolean'],
            'allow_manual_entry' => ['boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var Account $account */
            $account = $this->route('account');
            $parentId = $this->input('parent_id');

            if (! $parentId) {
                return;
            }

            if ((int) $parentId === $account->id) {
                $validator->errors()->add('parent_id', 'An account cannot be its own parent.');

                return;
            }

            // Cycle guard: the new parent must not be a descendant of this account.
            if ($account->descendants()->pluck('id')->contains((int) $parentId)) {
                $validator->errors()->add('parent_id', 'An account cannot be moved under its own descendant.');

                return;
            }

            $parent = Account::find($parentId);
            $type = $this->input('type', $account->type);

            if ($parent && $parent->type !== $type) {
                $validator->errors()->add('parent_id', 'Parent account must have the same type.');
            }

            // A posted-to account stops accepting entries once it has a child.
            if ($parent && ! $parent->canHaveChildren()) {
                $validator->errors()->add(
                    'parent_id',
                    "Account {$parent->code} ({$parent->name}) has journal entries of its own and cannot be a parent."
                );
            }
        });
    }
}
