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

    /*
    | Field visits — docs/saas-launch-plan.md §8.5. The witness's confirmation link is sent
    | at check-out when a company has switched it on, and works for this many hours.
    */
    'field_visits' => [
        'enabled' => env('CRM_FIELD_VISITS_ENABLED', false),
        'confirmation_link_hours' => env('CRM_FIELD_VISIT_LINK_HOURS', 24),

        /*
        | Judging where a check-in happened — docs/saas-launch-plan.md §14.10.
        |
        | `off` records the coordinates and judges nothing, which is how this worked
        | before. `flag` writes a verdict and lets the check-in through. `refuse` turns
        | away a check-in that is not at the place.
        |
        | `flag` is the default because a company that has not thought about it wants to
        | see the problem rather than have its reps locked out of their own calls on a
        | day the signal is poor. Per company through TenantSettings.
        |
        | Nothing is judged until somebody has pinned the place, whatever this says.
        */
        'geofence_mode' => env('CRM_FIELD_VISIT_GEOFENCE_MODE', 'flag'),

        /*
        | How vague a phone's reading may be and still be judged, in metres. Anything
        | vaguer is recorded as "could not tell" rather than called away — indoors, that
        | is most readings, and calling a rep absent for standing inside a shop would be
        | the fastest way to have the whole feature switched off.
        */
        'accuracy_limit_m' => env('CRM_FIELD_VISIT_ACCURACY_LIMIT_M', 100),

        /*
        | Whether only enrolled staff may be sent on visits. Off, as it has always been:
        | a company of eighty with six reps turns it on to stop the other seventy-four
        | filling the picker.
        */
        'enrolment_required' => env('CRM_FIELD_VISIT_ENROLMENT_REQUIRED', false),
    ],

];
