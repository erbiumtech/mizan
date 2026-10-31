<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Mint the VAPID pair that lets this installation push — docs/desktop-mobile-plan.md A3.
 *
 *   php artisan webpush:keys
 *
 * Prints the two lines for .env and changes nothing itself: the private key is a credential, and a
 * command that wrote it into a file would be choosing where credentials live. Rotating the pair
 * invalidates every subscription made with the old public key; browsers re-subscribe on their next visit.
 */
class WebPushKeys extends Command
{
    protected $signature = 'webpush:keys';

    protected $description = 'Generate a VAPID key pair for Web Push and print the .env lines';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->info('Add both to .env (and WEBPUSH_SUBJECT=mailto:you@example.com). Existing subscriptions, if any, re-subscribe on their next visit.');

        return self::SUCCESS;
    }
}
