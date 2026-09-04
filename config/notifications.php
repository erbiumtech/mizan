<?php

return [
    /*
    | Whether a change to a record tells the people it belongs to — its owner, whoever
    | created it, whoever asked for it. Delivered to the bell (and over the socket), never
    | by email: a mail per edit is a mailbox nobody reads.
    |
    | Per company, through TenantSettings: this is the shipped default, and Company
    | Settings overrides it. See App\Modules\Core\Listeners\NotifyRecordAudience.
    */
    'record_changes' => (bool) env('NOTIFY_RECORD_CHANGES', true),
];
