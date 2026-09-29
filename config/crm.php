<?php

/*
| CRM.
|
| Pipelines, stages and lost reasons are ROWS — every company renames them, and a config
| array would make a rename a deploy. Only the public capture endpoint's limits are here,
| because they are an installation concern rather than a company's.
*/

return [

    /*
    | The public lead-capture endpoint: POST /leads/{company}/{token}.
    |
    | Off, and tokenless, until a company turns it on and generates one. **Two gates, not
    | one** — the same shape the public status page uses, so a leaked token can be closed by
    | switching the setting off rather than by a deploy.
    */
    'lead_capture' => [
        'enabled' => env('CRM_LEAD_CAPTURE_ENABLED', false),
        'token' => env('CRM_LEAD_CAPTURE_TOKEN'),

        /*
        | Requests a minute, per company and per IP within that.
        |
        | Over the limit is **rejected silently** — the same 202 as a success. An endpoint
        | that answers 429 tells a bot exactly how to pace itself.
        |
        | Two limits rather than one: a single misbehaving integration should not exhaust a
        | ceiling shared with a legitimate one, and somebody rotating IPs should still meet
        | the company-wide figure.
        */
        'per_minute' => env('CRM_LEAD_CAPTURE_PER_MINUTE', 30),
        'per_minute_per_ip' => env('CRM_LEAD_CAPTURE_PER_MINUTE_PER_IP', 5),
    ],

];
