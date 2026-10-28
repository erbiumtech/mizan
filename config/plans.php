<?php

/**
 * The plan registry: what a customer pays for, and what that licenses.
 *
 * A *profile* (config/company_profiles.php) says what kind of business a company
 * is — what to seed, what to recommend. A *plan* says which modules the customer
 * may be licensed. They are different axes and stay so: a software house on
 * Starter and a restaurant on Starter are the same plan and different profiles.
 * What a company is licensed at provisioning is profile ∩ plan; see
 * App\Support\Plans and docs/saas-launch-plan.md §3.
 *
 * A plan is a preset, not a lock. The super admin's licence toggles on the
 * platform panel still grant anything to anyone; a custom deal is a licence
 * grant plus a custom price on the agreement, not a fourth plan.
 *
 * Every price here is per active employee per month, in the listed currency,
 * tax excluded, and every figure is a proposal that lives in this file: changing
 * a price is editing a line, and git is the price history. The reasoning behind
 * the numbers is §3.1 of the plan; the short version is that USD sits on the
 * nearest competitor's list, PKR is a purchasing-power list for the home market
 * and is never indexed to the exchange rate, and the ten-seat floor prices the
 * account rather than the seat.
 *
 * Invariants, asserted by PlanTest because each fails silently otherwise:
 *
 *  - every plan's resolved module list is closed under `requires`, so nothing
 *    is sold that can never be switched on;
 *  - every module that is not Core is sold by some plan or add-on, so there is
 *    no module nobody can buy;
 *  - starter ⊂ growth ⊂ business, which `includes` makes true by construction;
 *  - every plan and add-on prices every currency in `currencies`.
 *
 * `modules` lists what a plan ADDS to what it `includes`; App\Support\Plans
 * resolves the full set. A plan with no `includes` lists everything it sells.
 */
return [

    'currencies' => ['PKR', 'USD', 'EUR'],

    // Every plan, every currency. A three-person firm pays as ten; that is what
    // a minimum is for. Five is the lever if trials from very small firms stall.
    'minimum_seats' => 10,

    // What a self-serve signup starts on, and for how long. Nothing here is read
    // until the signup door exists (plan §5); the provisioner takes a plan and
    // stores it, and that is all it knows.
    'trial' => ['plan' => 'growth', 'days' => 14],

    // The signup door (plan §5): submissions per minute per address before the
    // form answers silently, and how long the confirmation link stays valid.
    'signup' => ['per_minute' => 5, 'link_hours' => 24],

    'plans' => [

        'starter' => [
            'label' => 'HR',
            'description' => 'Employee records, leave, attendance, onboarding and exit, and monthly progress reports.',
            'modules' => ['employees', 'leave', 'attendance', 'lifecycle', 'mpr'],
            'prices' => ['PKR' => 400, 'USD' => 3, 'EUR' => 3],
        ],

        'growth' => [
            'label' => 'HR and payroll',
            'description' => 'Starter plus payroll with the FBR and EOBI files, advances, expense claims, recruitment, appraisals, projects and timesheets.',
            'includes' => 'starter',
            // `devices` joins this list when the module lands (plan §8.7).
            'modules' => ['payroll', 'advances', 'expenses', 'recruitment', 'performance', 'projects', 'timesheets'],
            'prices' => ['PKR' => 800, 'USD' => 6, 'EUR' => 6],
        ],

        'business' => [
            'label' => 'HR, payroll and books',
            'description' => 'Growth plus the double-entry ledger, invoicing, quotations, inventory, CRM, campaigns, support and client billing.',
            'includes' => 'growth',
            'modules' => ['accounting', 'invoicing', 'quotations', 'inventory', 'crm', 'campaigns', 'support', 'billing'],
            'prices' => ['PKR' => 1400, 'USD' => 10, 'EUR' => 10],
            'custom_domain' => true,
        ],

    ],

    // Flat per company per month, not per seat: construction is job-centric and
    // a personal account has no employees, so a per-seat price on either would
    // be a price on the wrong thing. Business only, because of what they require.
    'add_ons' => [

        'construction' => [
            'label' => 'Construction',
            'requires_plan' => 'business',
            'modules' => ['construction', 'construction_costing', 'construction_contracts', 'construction_field', 'construction_qhse'],
            'prices' => ['PKR' => 15000, 'USD' => 100, 'EUR' => 100],
        ],

        'personal_finance' => [
            'label' => 'Personal finance',
            'requires_plan' => 'business',
            'modules' => ['personal_finance'],
            'prices' => ['PKR' => 1000, 'USD' => 8, 'EUR' => 8],
        ],

    ],

];
