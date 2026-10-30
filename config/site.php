<?php

/*
 * The public site — docs/company-website-plan.md.
 *
 * What is decided once and must read the same on every page lives here rather than in a
 * setting somebody can edit on one page and forget on another.
 */
return [

    // Off restores the old `/` → `/admin` redirect and answers 404 for every site route, for an
    // installation that runs the product without selling it.
    'enabled' => (bool) env('SITE_ENABLED', true),

    // docs/saas-launch-plan.md §12.5, decided 2026-10-30. Shown on the pricing page and the home page
    // from this one place.
    'residency' => 'Hosted in Pakistan, backed up in Pakistan.',

    // The currency the pricing page opens on; `?currency=` switches among config('plans.currencies').
    'default_currency' => 'PKR',

    // The contact form's silent per-address limit — the signup door's rule 3, same shape.
    'contact' => ['per_minute' => 3],

];
