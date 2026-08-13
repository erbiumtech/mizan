<?php

/*
| Hiring.
|
| One setting, and it is about data protection rather than workflow.
*/

return [

    /*
    | How long an applicant's record is kept after they were rejected.
    |
    | **This is the most sensitive data this application holds, and it is held about
    | people the company never hired.** Two years is long enough to recognise somebody
    | applying again — the reason `applicants` is a separate table at all — and short
    | enough not to be a filing cabinet of strangers' CVs.
    |
    | Pruning deletes the CV file with the row. The company is the data controller, and
    | the help text says so.
    |
    | Keyed on the REJECTION, not the row's age: somebody still in a process, or hired, is
    | never pruned however long ago they applied.
    */
    'retention_months' => env('RECRUITMENT_RETENTION_MONTHS', 24),

];
