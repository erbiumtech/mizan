<?php

/*
| Work: the installation defaults.
|
| Read through setting('work.x'), which returns the company's override when it has one and
| falls back here when it does not — the same three-tier resolution the other modules use. The
| company edits these under Company Settings → Company → Work (WorkDefaultsSettingsSection).
*/

return [

    /*
    | Points or hours — docs/project-management-plan.md §16.3.
    |
    | Both columns exist on every item; this says which the forms lead with and the reports sum.
    | Hours by default, because the timesheet already measures hours and a company that has never
    | run a sprint has never seen a point. A software team flips it.
    */
    'estimation_unit' => env('WORK_ESTIMATION_UNIT', 'hours'),

    /*
    | The morning digest — §10.3.
    |
    | Items due today, items assigned since yesterday, items blocked on you: one notification per
    | user who has any, at this time, in the application's timezone. Off for anybody who has turned
    | the *work* category off, and off for the company here.
    */
    'digest_enabled' => env('WORK_DIGEST_ENABLED', true),
    'digest_time' => env('WORK_DIGEST_TIME', '08:00'),

    /*
    | What a merged pull request does to the items it names — §8.4. Phase 7 reads this; a project
    | may override it on its own settings.
    */
    'merge_moves_to_done' => env('WORK_MERGE_MOVES_TO_DONE', true),

];
