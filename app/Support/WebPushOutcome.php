<?php

namespace App\Support;

/** What a push service said about one delivery — see App\Support\Contracts\WebPushTransport. */
enum WebPushOutcome: string
{
    case Sent = 'sent';

    /** 404 or 410: the browser unsubscribed, or the subscription aged out. The token is dead and is deleted. */
    case Expired = 'expired';

    /** Anything else; the token is kept and the failure logged, because the next push may well succeed. */
    case Failed = 'failed';
}
