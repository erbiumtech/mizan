<?php

namespace App\Modules\Quotations\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Quotations\Models\Quotation;

/** A quote and its lines are one document, so one group covers both. */
class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('QuotationView');
    }

    public function view(User $user, Quotation $record): bool
    {
        return $user->hasPermissionTo('QuotationView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('QuotationCreate');
    }

    /**
     * Only a draft.
     *
     * A sent quote is in the customer's inbox. Editing it makes this system disagree with what
     * they are looking at, and nothing can then say which version was agreed — which is why
     * revising creates version 2 instead.
     */
    public function update(User $user, Quotation $record): bool
    {
        return $user->hasPermissionTo('QuotationUpdate') && $record->isDraft();
    }

    /** Never once it has become an invoice: it is that invoice's origin. */
    public function delete(User $user, Quotation $record): bool
    {
        return $user->hasPermissionTo('QuotationDelete') && $record->invoice_id === null;
    }

    /**
     * Converting is its own permission, and needs the module it converts into.
     *
     * Turning a quote into an invoice starts something that, once issued and transmitted to
     * FBR, cannot be freely undone after 72 hours — a bigger act than editing a document.
     */
    public function convert(User $user, Quotation $record): bool
    {
        return $user->hasPermissionTo('QuotationConvert')
            && $record->isAccepted()
            && $record->invoice_id === null
            && app(\App\Modules\Quotations\Services\QuotationService::class)->canConvert();
    }
}
