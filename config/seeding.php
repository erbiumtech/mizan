<?php

return [

    /*
    | The seeders create dummy data by default (see database/seeders — the real
    | values live in database/seeders/Production). On an installation that
    | already has a real company and super admin, point these at them so
    | `db:seed` matches the existing records instead of creating a second,
    | dummy set alongside them.
    |
    | These belong in config rather than being read with env() at the point of
    | use: `php artisan config:cache` makes env() return null everywhere else,
    | which would silently drop the overrides on a production host.
    */
    'admin_email' => env('SEED_ADMIN_EMAIL'),

    'company_name' => env('SEED_COMPANY_NAME'),

    'company_slug' => env('SEED_COMPANY_SLUG'),
];
