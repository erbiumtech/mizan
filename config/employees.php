<?php

return [
    /*
    | The prefix a company's employee codes are numbered from — docs/saas-launch-plan.md
    | §14.10. `EMP-0001`, `MZN-0001`, whatever a company already writes on its badges.
    |
    | Per company through TenantSettings; this is only the default for one that has not
    | chosen. Changing it does not renumber anybody — the next code is taken from the
    | highest in use *with that prefix*, so a company that switches starts a second
    | sequence at one. `employees:renumber` is what moves the old rows across.
    */
    'code_prefix' => env('EMPLOYEE_CODE_PREFIX', 'EMP-'),
];
