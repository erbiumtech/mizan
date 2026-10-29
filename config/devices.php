<?php

/*
| Biometric devices — docs/saas-launch-plan.md §8.7.
*/
return [
    // What the handshake tells the device its clock is in. Pakistan has no DST.
    'timezone_offset_hours' => env('DEVICES_TIMEZONE_OFFSET_HOURS', 5),

    // Calls a minute per serial. A device in realtime mode makes one per punch and
    // one poll a minute; sixty is generous for a factory gate and small for a scanner.
    'per_minute' => env('DEVICES_PER_MINUTE', 60),
];
