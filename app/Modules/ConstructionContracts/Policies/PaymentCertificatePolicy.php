<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\Core\Models\User;

/**
 * Who may prepare, certify, void and invoice a certificate.
 *
 * **`ConstructionCertificateCertify` is its own permission and §18.2 lists it among the names that matter.**
 * Issuing a certificate starts the payment period, creates an entitlement the other party will enforce, and
 * produces a document an adjudicator reads. Preparing one is a surveyor's arithmetic; issuing it is the
 * certifier's decision, and the two are different people on every job that has a certifier at all.
 *
 * **There is no `update` once issued and no `delete` at all.** A certificate is a statement of a moment that
 * somebody outside this company holds a copy of. The ways to correct one are to void it with a reason or to let
 * the next certificate absorb the difference — which the cumulative design makes automatic.
 */
class PaymentCertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCertificateView');
    }

    public function view(User $user, PaymentCertificate $certificate): bool
    {
        return $user->can('ConstructionCertificateView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionCertificateCreate');
    }

    public function update(User $user, PaymentCertificate $certificate): bool
    {
        return $user->can('ConstructionCertificateCreate') && $certificate->isDraft();
    }

    /** The decision that starts the payment clock. */
    public function certify(User $user, PaymentCertificate $certificate): bool
    {
        return $user->can('ConstructionCertificateCertify') && $certificate->isDraft();
    }

    /**
     * Voiding is the certifier's power too, and deliberately not the preparer's.
     *
     * An issued certificate the other party is relying on cannot be withdrawn by whoever typed it.
     */
    public function void(User $user, PaymentCertificate $certificate): bool
    {
        return $user->can('ConstructionCertificateCertify')
            && $certificate->status !== PaymentCertificate::STATUS_VOID;
    }

    /**
     * Raising the invoice is a finance act, separately granted.
     *
     * §18's reason for keeping Invoicing guarded rather than required is the same reason this is its own
     * permission: large contractors certify in the commercial department and invoice in finance.
     */
    public function invoice(User $user, PaymentCertificate $certificate): bool
    {
        return $user->can('ConstructionCertificateInvoice')
            && $certificate->isIssued()
            && $certificate->invoice_id === null;
    }
}
