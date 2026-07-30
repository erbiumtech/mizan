<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Modules\Accounting\Models\Account::class);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['boolean'],
            'allow_manual_entry' => ['boolean'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $parentId = $this->input('parent_id');

            if ($parentId && ($parent = \App\Modules\Accounting\Models\Account::find($parentId))) {
                if ($parent->type !== $this->input('type')) {
                    $validator->errors()->add('parent_id', 'Parent account must have the same type.');
                }
            }
        });
    }
}
