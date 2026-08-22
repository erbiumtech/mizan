<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 *
 * **Quality, health, safety and environment — `docs/construction-management-plan.md` §17.** ITPs and their inspections,
 * non-conformance with CAPA, incidents, one actions table for all of it, permits, toolbox talks, the induction register
 * and the indicators.
 *
 * **It requires only `construction`**, which is the same claim §18 makes for the field module and for the same reason:
 * an ITP names a job, a WBS node and a location, and an inspection needs none of the books, no contract and no cost
 * ledger. A contractor buying quality and safety while keeping its commercial side elsewhere is a real customer — and
 * ISO 9001 and ISO 45001 certification is often the *reason* a contractor buys software at all.
 *
 * Four modules are **guarded rather than required**, and each absence makes this smaller rather than broken:
 *
 *  - `construction_field` for §17.6's exposure hours, which come from the daily log's manpower. **This is the one place
 *    in the module where an absence is not graceful**, and §17.6 says so: with no diary the denominator is zero and
 *    every frequency rate renders as 0.00, which reads as a perfect safety record and means nobody filled anything in.
 *    The indicator page refuses to print a rate instead.
 *  - `construction_contracts` for the contract item an inspection or an NCR names, and for §17.2's proposed deduction —
 *    which the certification service *offers* and never applies.
 *  - `employees` for an injured person who is on the payroll. §17.3 requires the free-text name to work on its own,
 *    because a subcontractor's labourer is not in this system and pretending otherwise loses the record entirely.
 *  - `invoicing` for the contact an inspection party, an NCR owner or a permit holder points at.
 */
return [
    'key' => 'construction_qhse',
    'label' => 'Construction Quality & Safety',
    'description' => 'ITPs and inspections with hold points, non-conformance and CAPA, incidents, permits, toolbox talks, the induction register and the QHSE indicators.',
    'requires' => [
        'construction',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\ConstructionQhse\ConstructionQhsePlugin::class,

    'models' => [
        'App\\Models\\Itp' => \App\Modules\ConstructionQhse\Models\Itp::class,
        'App\\Models\\ItpActivity' => \App\Modules\ConstructionQhse\Models\ItpActivity::class,
        'App\\Models\\ItpActivityParty' => \App\Modules\ConstructionQhse\Models\ItpActivityParty::class,
        'App\\Models\\Inspection' => \App\Modules\ConstructionQhse\Models\Inspection::class,
        'App\\Models\\InspectionCheck' => \App\Modules\ConstructionQhse\Models\InspectionCheck::class,
        'App\\Models\\Ncr' => \App\Modules\ConstructionQhse\Models\Ncr::class,
        'App\\Models\\QhseAction' => \App\Modules\ConstructionQhse\Models\QhseAction::class,
        'App\\Models\\Incident' => \App\Modules\ConstructionQhse\Models\Incident::class,
        'App\\Models\\IncidentWitness' => \App\Modules\ConstructionQhse\Models\IncidentWitness::class,
        'App\\Models\\IncidentPhoto' => \App\Modules\ConstructionQhse\Models\IncidentPhoto::class,
        'App\\Models\\Permit' => \App\Modules\ConstructionQhse\Models\Permit::class,
        'App\\Models\\SitePersonnel' => \App\Modules\ConstructionQhse\Models\SitePersonnel::class,
        'App\\Models\\Competency' => \App\Modules\ConstructionQhse\Models\Competency::class,
        'App\\Models\\ToolboxTalk' => \App\Modules\ConstructionQhse\Models\ToolboxTalk::class,
        'App\\Models\\ToolboxTalkAttendee' => \App\Modules\ConstructionQhse\Models\ToolboxTalkAttendee::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ConstructionQhse\\ItpResource' => \App\Modules\ConstructionQhse\Filament\Resources\Itps\ItpResource::class,
        'App\\Filament\\Resources\\ConstructionQhse\\InspectionResource' => \App\Modules\ConstructionQhse\Filament\Resources\Inspections\InspectionResource::class,
        'App\\Filament\\Resources\\ConstructionQhse\\NcrResource' => \App\Modules\ConstructionQhse\Filament\Resources\Ncrs\NcrResource::class,
        'App\\Filament\\Resources\\ConstructionQhse\\QhseActionResource' => \App\Modules\ConstructionQhse\Filament\Resources\QhseActions\QhseActionResource::class,
        'App\\Filament\\Resources\\ConstructionQhse\\IncidentResource' => \App\Modules\ConstructionQhse\Filament\Resources\Incidents\IncidentResource::class,
        'App\\Filament\\Resources\\ConstructionQhse\\PermitResource' => \App\Modules\ConstructionQhse\Filament\Resources\Permits\PermitResource::class,
        'App\\Filament\\Resources\\ConstructionQhse\\SitePersonnelResource' => \App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\SitePersonnelResource::class,
        'App\\Filament\\Resources\\ConstructionQhse\\ToolboxTalkResource' => \App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\ToolboxTalkResource::class,
    ],

    'permission_groups' => [
        'ConstructionQhse',
    ],

    /*
     * **`ConstructionInspectionRelease` is its own name, and it is the one permission in this module that must be.**
     *
     * §17.1: "the whole function of a hold point is that work may not proceed past it". Releasing one authorises the
     * next operation to start — a pour, a backfill, a cladding line — and it is the act a certification body audits.
     * Whoever *records* that an inspection happened is not necessarily whoever may say the work may proceed, and on a
     * site where those are the same person the hold point has no function at all.
     *
     * Requesting and recording inspections is one grant, deliberately: the request goes out and the result comes back
     * to the same engineer, and splitting them would leave results sitting in a notebook while the register says the
     * inspection is still awaited.
     */
    'permissions' => [
        ['name' => 'ConstructionItpView', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionItpUpdate', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionItpApprove', 'group' => 'ConstructionQhse'],

        ['name' => 'ConstructionInspectionView', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionInspectionUpdate', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionInspectionRelease', 'group' => 'ConstructionQhse'],

        /*
         * Non-conformance (§17.2), and **`ConstructionNcrDisposition` is the second name in this module that has to be
         * its own.** §17.2 calls disposition "the field that decides whether money changes hands": *use as is* and
         * *concession requested* accept work that does not meet the specification, which is the client giving something
         * up, and that is not a call for whoever noticed the defect.
         *
         * Proposing a deduction rides on the same grant, because the two decisions are made in the same conversation
         * and **the proposal withholds nothing.** The act that moves money is on the other side of the module boundary
         * entirely, taken by whoever signs the certificate.
         */
        ['name' => 'ConstructionNcrView', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionNcrUpdate', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionNcrDisposition', 'group' => 'ConstructionQhse'],

        /*
         * The one actions register (§17.4). **`ConstructionActionVerify` is its own name** for the same reason a punch
         * item's passed re-inspection is: "done" is the assignee's claim and "verified" is somebody else's
         * confirmation. One grant for both would let whoever caused a finding close it, and an actions register nobody
         * believes is a register nobody reads.
         *
         * Raising and completing are one grant, because on a real site the person who writes the action down and the
         * person who reports it done are frequently the same — and splitting them would leave finished work showing as
         * outstanding for want of a second click.
         */
        ['name' => 'ConstructionActionView', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionActionUpdate', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionActionVerify', 'group' => 'ConstructionQhse'],

        /*
         * Incidents (§17.3), and **`ConstructionIncidentReport` is the widest grant in this module on purpose.**
         *
         * §17.3's leading indicator is near misses per lost-time injury, and a permission that made reporting hard would
         * suppress exactly the number it most needs. Anybody who can see something nearly go wrong should be able to
         * write it down — which is why reporting sits with Employee and is not gated behind a safety role.
         *
         * **`ConstructionIncidentInvestigate` is separate** because closing an incident asserts that its cause is
         * understood and its lesson recorded, and the person who was involved is not the person to conclude that. It
         * also carries the authority report, which is a statutory duty with somebody's name against it.
         */
        ['name' => 'ConstructionIncidentView', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionIncidentReport', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionIncidentInvestigate', 'group' => 'ConstructionQhse'],

        /*
         * Permits to work (§17.5), and **`ConstructionPermitIssue` is the sharpest segregation in this module.**
         *
         * Issuing a permit authorises high-risk work: hot work in a finished building, entry into a confined space, a
         * lift over a live road. The person who wants to do the work is the last person who should decide it is safe to,
         * and every permit-to-work regime in the world is built on that separation.
         *
         * Requesting is wide, because a supervisor who cannot raise a permit is a supervisor whose gang works without
         * one. **Suspending is on the wide grant too** — a permit that can only be suspended by whoever issued it is a
         * permit that stays live while somebody goes looking for them — while resuming and closing out are on the
         * issuing grant, because both are assertions about safety.
         */
        ['name' => 'ConstructionPermitView', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionPermitRequest', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionPermitIssue', 'group' => 'ConstructionQhse'],

        /*
         * The induction register, competencies and toolbox talks (§17.5). **Two names, and the update grant is
         * deliberately wide.**
         *
         * Putting somebody on the register, inducting them, recording their tickets and writing up a toolbox talk is
         * *gate work*: it happens at seven in the morning, done by whoever is at the gate, for people who arrived that
         * day. A permission that made it a supervisor's job would produce a register that lags the site by a week — and
         * a register that lags is one nobody trusts to say who is cleared to work.
         *
         * Toolbox talks share the grant rather than earning their own, because both are the same job done by the same
         * person at the same moment, and a second permission would only mean one of the two got filled in.
         *
         * `View` is separate because this register holds names, phone numbers and medical certificates — the most
         * personal data in the module.
         */
        ['name' => 'ConstructionPersonnelView', 'group' => 'ConstructionQhse'],
        ['name' => 'ConstructionPersonnelUpdate', 'group' => 'ConstructionQhse'],
    ],

    'role_grants' => [
        /*
         * Requesting and recording inspections is site's, the fifth create grant site staff hold in this suite. The
         * person who can see that the rebar is ready is standing in front of it, and an inspection they cannot request
         * is a hold point that gets passed by telephone.
         */
        'Employee' => [
            'ConstructionItpView',
            'ConstructionInspectionView',
            'ConstructionInspectionUpdate',
            // Anybody who can see the work is wrong should be able to say so. A register that made this difficult would
            // record the nonconformities somebody remembered to mention.
            'ConstructionNcrView',
            'ConstructionNcrUpdate',
            // Actions are the working list, and site is who works from it.
            'ConstructionActionView',
            'ConstructionActionUpdate',
            // The widest grant here: an incident nobody can report is an incident nobody reports.
            'ConstructionIncidentView',
            'ConstructionIncidentReport',
            // A supervisor who cannot raise a permit is a supervisor whose gang works without one.
            'ConstructionPermitView',
            'ConstructionPermitRequest',
            // Gate work: whoever is at the gate at seven in the morning keeps this register.
            'ConstructionPersonnelView',
            'ConstructionPersonnelUpdate',
        ],
        'Accountant' => [
            'ConstructionItpView',
            'ConstructionInspectionView',
            'ConstructionInspectionUpdate',
            'ConstructionNcrView',
            'ConstructionNcrUpdate',
            'ConstructionActionView',
            'ConstructionActionUpdate',
            'ConstructionIncidentView',
            'ConstructionIncidentReport',
            'ConstructionPermitView',
            'ConstructionPermitRequest',
            // Gate work: whoever is at the gate at seven in the morning keeps this register.
            'ConstructionPersonnelView',
            'ConstructionPersonnelUpdate',
        ],
        /*
         * **Approving an ITP and releasing a hold point are both Manager's**, and for the same reason: an ITP is the
         * document a certification body audits against, and a hold-point release authorises the next operation to
         * start. Neither is the same act as writing an inspection down.
         */
        'Manager' => [
            'ConstructionItpUpdate',
            'ConstructionItpApprove',
            'ConstructionInspectionRelease',
            // Accepting work that does not meet the specification, and proposing what should be withheld for it.
            'ConstructionNcrDisposition',
            // Confirming somebody else's work is done.
            'ConstructionActionVerify',
            // Concluding what caused something, and telling the authority.
            'ConstructionIncidentInvestigate',
            // Authorising high-risk work, and signing that the area was made safe afterwards.
            'ConstructionPermitIssue',
        ],
    ],

    'navigation' => [
        'Quality & Safety' => 'construction',
    ],
];
