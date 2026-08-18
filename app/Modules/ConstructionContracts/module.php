<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 *
 * **It does not require `invoicing`, and `docs/construction-management-plan.md` §18 calls this the sharpest
 * fork in that section.** The tempting precedent points the other way — `quotations` requires Invoicing
 * because "a quote whose whole point is becoming an invoice, and which can never convert, is a PDF
 * generator". Copying that reflexively would be wrong, because **a payment certificate is not a quote**. A
 * certificate is itself a contractual instrument: it starts the payment period, the client's surveyor
 * countersigns it, an adjudicator reads it, and under FIDIC its issue is an obligation of the Engineer
 * whether or not anybody raises a tax invoice. Large contractors run certification in the commercial
 * department and invoicing in finance, frequently on different systems.
 *
 * So without Invoicing the *Raise invoice* action is absent, `certificates.invoice_id` stays null, and the
 * register — with its retention ledger, its variation history and its printed forms — is still the whole
 * deliverable. The honest cost, stated in §18: retention held has no ledger account until Invoicing is
 * licensed, so the register is a contractual record rather than a financial one.
 *
 * It requires `construction` for the obvious reason: a contract names a job, and its items name WBS nodes and
 * cost codes.
 */

return [
    'key' => 'construction_contracts',
    'label' => 'Construction Contracts',
    'description' => 'Head contracts and subcontracts under FIDIC and AIA: the item schedule, variations, progress claims, payment certificates and the retention ledger.',
    'requires' => [
        'construction',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\ConstructionContracts\ConstructionContractsPlugin::class,

    'models' => [
        'App\\Models\\Contract' => \App\Modules\ConstructionContracts\Models\Contract::class,
        'App\\Models\\ContractItem' => \App\Modules\ConstructionContracts\Models\ContractItem::class,
        'App\\Models\\Variation' => \App\Modules\ConstructionContracts\Models\Variation::class,
        'App\\Models\\VariationItem' => \App\Modules\ConstructionContracts\Models\VariationItem::class,
        'App\\Models\\ProgressClaim' => \App\Modules\ConstructionContracts\Models\ProgressClaim::class,
        'App\\Models\\ProgressClaimLine' => \App\Modules\ConstructionContracts\Models\ProgressClaimLine::class,
        'App\\Models\\PaymentCertificate' => \App\Modules\ConstructionContracts\Models\PaymentCertificate::class,
        'App\\Models\\CertificateLine' => \App\Modules\ConstructionContracts\Models\CertificateLine::class,
        'App\\Models\\CertificateDeduction' => \App\Modules\ConstructionContracts\Models\CertificateDeduction::class,
        'App\\Models\\RetentionMovement' => \App\Modules\ConstructionContracts\Models\RetentionMovement::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ConstructionContracts\\ContractResource' => \App\Modules\ConstructionContracts\Filament\Resources\Contracts\ContractResource::class,
        'App\\Filament\\Resources\\ConstructionContracts\\VariationResource' => \App\Modules\ConstructionContracts\Filament\Resources\Variations\VariationResource::class,
        'App\\Filament\\Resources\\ConstructionContracts\\ProgressClaimResource' => \App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\ProgressClaimResource::class,
        'App\\Filament\\Resources\\ConstructionContracts\\PaymentCertificateResource' => \App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\PaymentCertificateResource::class,
        'App\\Filament\\Resources\\ConstructionContracts\\RetentionMovementResource' => \App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\RetentionMovementResource::class,
    ],

    'permission_groups' => [
        'ConstructionContract',
    ],

    /*
     * The item schedule rides on the contract's group, following §18.2's Leave precedent: "eight more
     * permission names for two tables nobody navigates to is eight more rows in every role form for no
     * decision anybody makes separately". A schedule line is not a decision separate from the contract it
     * prices.
     *
     * `ConstructionContractExecute` is its own name because it is its own decision: execution freezes the
     * scheduled values every later certificate is measured against.
     */
    'permissions' => [
        ['name' => 'ConstructionContractView', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionContractCreate', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionContractUpdate', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionContractExecute', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionContractDelete', 'group' => 'ConstructionContract'],

        /*
         * Variations ride on the contract's group — same screenful, same people — but **`Price` and `Approve`
         * are separate names**, and §18.2 lists both among the non-CRUD ones that matter. Pricing is the
         * surveyor's assessment of what a change is worth; approving it commits the employer's money and moves
         * the figure out of the forecast and into the certified contract sum. One person holding both is the
         * segregation of duties this suite keeps everywhere else.
         */
        ['name' => 'ConstructionVariationView', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionVariationCreate', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionVariationUpdate', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionVariationPrice', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionVariationApprove', 'group' => 'ConstructionContract'],

        /*
         * Claims and certificates share a set, because the claim is the document the certificate answers and
         * nobody maintains one without the other. **`Certify` and `Invoice` are separate names** and §18.2 lists
         * both: issuing a certificate starts the payment period and creates an entitlement the other party will
         * enforce, which is the certifier's decision rather than the surveyor's arithmetic; raising the invoice
         * is finance's, and in a large contractor it happens in a different department on a different system.
         */
        ['name' => 'ConstructionCertificateView', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionCertificateCreate', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionCertificateCertify', 'group' => 'ConstructionContract'],
        ['name' => 'ConstructionCertificateInvoice', 'group' => 'ConstructionContract'],

        /*
         * The retention register reads on the certificate permission — same audience, same screenful — and
         * **`Release` is its own name**, which §18.2 lists. Releasing retention hands over money the contract
         * entitled this company to hold, and on a job of any size it is the largest single payment decision
         * anybody makes; an early release, which FIDIC 14.9 contemplates, is that decision taken sooner.
         */
        ['name' => 'ConstructionRetentionRelease', 'group' => 'ConstructionContract'],
    ],

    'role_grants' => [
        // A site engineer reads the contract and the variations they are building to — the specification, the
        // dates, the damages, the instructions. An instruction nobody on site can see is work that gets built
        // to the superseded drawing. The money is the commercial side's to maintain.
        'Employee' => [
            'ConstructionContractView',
            'ConstructionVariationView',
        ],
        'Accountant' => [
            'ConstructionContractCreate',
            'ConstructionContractUpdate',
            'ConstructionContractView',
            // The surveyor raises and prices a variation. Agreeing the money is somebody else's.
            'ConstructionVariationCreate',
            'ConstructionVariationPrice',
            'ConstructionVariationUpdate',
            'ConstructionVariationView',
            // The surveyor prepares the claim and the draft certificate. Issuing it is the certifier's act.
            'ConstructionCertificateCreate',
            'ConstructionCertificateView',
        ],
        // Executing fixes the figures every later certificate is measured against, so it sits with the
        // approval powers rather than with whoever priced the bill.
        'Manager' => [
            // Certifying starts the payment period and creates an entitlement the other party will enforce.
            'ConstructionCertificateCertify',
            // Releasing retention hands back money the contract entitled this company to hold.
            'ConstructionRetentionRelease',
            'ConstructionContractExecute',
            // Approving agrees the employer's money and writes the schedule. Approval-shaped, kept away from
            // whoever priced it.
            'ConstructionVariationApprove',
        ],
        'CEO' => [
            // Raising the tax invoice off a certificate is a finance act, and the one that moves the figure into
            // the books — §18 keeps Invoicing guarded rather than required for the same reason.
            'ConstructionCertificateInvoice',
            'ConstructionContractDelete',
        ],
    ],

    /*
     * A group of its own in the construction domain (§18.2). Deliberately **flat** for now: §18.2 anticipates
     * branching it into head contract and subcontracts, and `NavigationTree`'s own rule is that "a branch
     * holding two things is a click in front of a list rather than a shorter one". Contracts holds five
     * entries when Phase 4 is complete, which is under that class's six-entry threshold; the branch arrives
     * with §12's subcontracts, compliance register and back-charges, which is what makes it a scroll.
     */
    'navigation' => [
        'Contracts' => 'construction',
    ],
];
