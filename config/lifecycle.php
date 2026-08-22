<?php

/*
| Joining and leaving.
|
| Checklists themselves are rows — HR writes them — so only the reminder cadence and
| the settlement conventions are here.
*/

return [

    /*
    | When to warn that a document is about to lapse, in days.
    |
    | Crossing each threshold warns ONCE. The health-check alerts taught the lesson: a
    | daily job that mails the same warning for thirty days trains somebody to filter
    | it, and then the one that mattered is filtered too.
    |
    | 60 to plan a renewal, 30 to chase it, 7 because it is now urgent.
    */
    'document_expiry_thresholds' => [60, 30, 7],

];
