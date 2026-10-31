<?php

/*
| Web Push — docs/desktop-mobile-plan.md A3, the transport Phase 0 Q4 chose.
|
| VAPID is a key pair this installation owns, not a vendor account: `php artisan webpush:keys` prints a
| pair, the public half goes into every subscription a browser makes and the private half signs every
| push. Unset, the channel is withdrawn from every notification before it becomes a job (see
| App\Support\Broadcasting::channels), the same way the broadcast channel is without a socket.
*/
return [
    'public_key' => env('WEBPUSH_PUBLIC_KEY'),
    'private_key' => env('WEBPUSH_PRIVATE_KEY'),

    // A mailto: or https: URL the push services may contact about this sender. Falls back to the mail
    // from-address, which every installation already has.
    'subject' => env('WEBPUSH_SUBJECT'),

    // Seconds a push service holds an undelivered message for a phone that is off. A day: a payslip
    // that arrives tomorrow is still news; one that arrives next week is a surprise.
    'ttl' => (int) env('WEBPUSH_TTL', 86400),
];
