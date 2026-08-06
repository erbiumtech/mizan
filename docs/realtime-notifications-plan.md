# Real-time Notifications — Implementation Plan

**Status:** Implemented (2026-08-06) — see [Implementation notes](#implementation-notes) for where the build diverged from this plan
**Created:** 2026-08-06

Goal: give the existing business notifications (payslips, expense claims, employee change requests, environment/certificate alerts) an in-app real-time signal on top of the email they already send — a Filament notification bell that updates instantly via WebSocket, not just on page reload.

## Decisions

- **Driver: Laravel Reverb**, self-hosted (first-party "Laravel standard" broadcaster, `laravel/reverb`). Production hosting can't run persistent processes, so Reverb will run on a small dedicated VPS separate from the app's hosting, reached over a public WSS URL. Provisioning that VPS is ops work outside this plan's code changes — this plan produces the app-side config plus deployable reference config (systemd unit + nginx snippet) for whoever sets that box up.
- **Scope**: convert all 7 existing notification classes to also deliver via `database` + `broadcast`, on top of their existing `mail` channel.
- **Queue**: switch `QUEUE_CONNECTION` from `database` to `redis` (Redis is already installed locally and its env vars already exist unused in `.env`).

## Facts verified against vendor source (not assumed)

- `App\Modules\Core\Models\User` uses `Notifiable` but does not override `receivesBroadcastNotificationsOn()`, so both Laravel's `BroadcastNotificationCreated` event and Filament's `Notifications`/`DatabaseNotifications` Livewire components independently compute the same private channel name: `str_replace('\\','.', User::class) . '.' . $id` → **`App.Modules.Core.Models.User.{id}`** (`vendor/laravel/framework/.../BroadcastNotificationCreated.php:69-71`, `vendor/filament/notifications/src/Livewire/Notifications.php:105-107`). `routes/channels.php` must authorize exactly this string, not the classic `App.Models.User.{id}`.
- `BroadcastChannel::getData()` falls back to `toArray()` only if `toBroadcast()` is absent (`vendor/laravel/framework/.../Channels/BroadcastChannel.php:62-64`); `DatabaseChannel` falls back to `toDatabase()` then `toArray()`. So each notification needs both `toDatabase()` and `toBroadcast()` explicitly defined to control the payload shape.
- `Filament\Notifications\Notification::getDatabaseMessage()` exists (`vendor/filament/notifications/src/Notification.php:230`) — this is the bridge that makes a plain Illuminate notification render correctly in Filament's bell/toast UI.
- Filament 5 bundles its own Echo/Pusher asset (`vendor/filament/filament/resources/js/echo.js`, registered `->core()` in `FilamentServiceProvider.php:101`, so it loads unconditionally on every panel page) which sets `window.EchoFactory` and `window.Pusher`. Every panel's `hasBroadcasting()` already defaults to `true` (`vendor/filament/filament/src/Panel/Concerns/HasBroadcasting.php:9`). The base layout (`vendor/filament/filament/resources/views/components/layout/base.blade.php:147-153`) already contains `window.Echo = new window.EchoFactory(@js(config('filament.broadcasting.echo')))` guarded by `filament()->hasBroadcasting() && config('filament.broadcasting.echo')` — so **no custom render hook or Vite entry is needed on the panels**; the only missing piece is publishing `config/filament.php` and filling in its `broadcasting.echo` array. `resources/js/app.js` is only loaded by `resources/views/welcome.blade.php` (the stock Laravel landing page) — irrelevant to the Filament panels.
- All 7 notifications' notifiables resolve to `App\Modules\Core\Models\User` instances (never a `Company`/tenant directly), fanned out via `Notification::send($users, ...)` — confirmed for every call site. One edge case: `PayslipIssued` sometimes uses `Notification::route('mail', $address)` (an on-demand notifiable with no `User`) when a payslip recipient has no account — `via()` must skip `database`/`broadcast` in that case.

## Implementation

### 1. Install Reverb + scaffold broadcasting
- `composer require laravel/reverb`
- `php artisan install:broadcasting --reverb` — non-interactively scaffolds `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, wires `->withBroadcasting(channels: __DIR__.'/../routes/channels.php')` into `bootstrap/app.php`, adds `REVERB_*`/`VITE_REVERB_*` + `BROADCAST_CONNECTION=reverb` to `.env` and `.env.example`, adds `laravel-echo`+`pusher-js` to `package.json`, and writes the `window.Echo = new Echo({broadcaster: 'reverb', ...})` block into `resources/js/bootstrap.js`.
- `npm install` to pull in the new JS deps.

### 2. Fix channel naming + queue connection
- Edit the generated `routes/channels.php` to authorize the real channel name:
  ```php
  use App\Modules\Core\Models\User;
  use Illuminate\Support\Facades\Broadcast;

  Broadcast::channel('App.Modules.Core.Models.User.{id}', function (User $user, int $id) {
      return $user->id === $id;
  });
  ```
- `.env` / `.env.example`: set `QUEUE_CONNECTION=redis`.
- `.env` / `.env.example`: set `REVERB_HOST` / `VITE_REVERB_HOST` to a placeholder (e.g. `ws.mpr.test`) with a comment that it must point at the dedicated Reverb VPS once provisioned; `REVERB_PORT=443`, `REVERB_SCHEME=https` (and matching `VITE_REVERB_*`) since it'll sit behind TLS.

### 3. Configure Filament's built-in Echo bootstrap
- `php artisan vendor:publish --tag=filament-config` to bring `config/filament.php` into the app.
- Fill in its `broadcasting.echo` array (read directly server-side, no `VITE_`-prefixed vars needed since Blade embeds it via `@js(...)`, not a Vite bundle):
  ```php
  'broadcasting' => [
      'echo' => [
          'broadcaster' => 'reverb',
          'key' => env('REVERB_APP_KEY'),
          'wsHost' => env('REVERB_HOST'),
          'wsPort' => env('REVERB_PORT', 80),
          'wssPort' => env('REVERB_PORT', 443),
          'authEndpoint' => '/broadcasting/auth',
          'forceTLS' => env('REVERB_SCHEME', 'https') === 'https',
          'enabledTransports' => ['ws', 'wss'],
      ],
  ],
  ```
  Filament's base layout picks this up automatically on every panel page — nothing else to wire up.

### 4. Enable Filament's database notifications bell
- Add `->databaseNotifications()` to both panel providers. Keep polling on at a reduced interval as a fallback in case a WS connection drops: `->databaseNotificationsPolling('60s')` (Echo push still delivers instantly when connected; polling is just the safety net, not the primary path).

### 5. Convert the 7 notification classes
Same mechanical change to each of:
- `app/Notifications/EmployeeChangeRequestSubmitted.php`
- `app/Notifications/PayslipIssued.php`
- `app/Notifications/PayslipRejected.php`
- `app/Modules/Projects/Notifications/EnvironmentDown.php`
- `app/Modules/Projects/Notifications/EnvironmentRecovered.php`
- `app/Modules/Projects/Notifications/CertificateExpiring.php`
- `app/Modules/Expenses/Notifications/ExpenseClaimSubmitted.php` (and `ExpenseClaimDecided.php`)

Pattern (`PayslipIssued` shown, using its existing `toMail()` content for title/body):
```php
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;

public function via(object $notifiable): array
{
    return $notifiable instanceof \App\Modules\Core\Models\User
        ? ['mail', 'database', 'broadcast']
        : ['mail'];
}

public function toDatabase(object $notifiable): array
{
    return FilamentNotification::make()
        ->title('Payslip issued')
        ->body('Your payslip for ' . $this->payslip->period_label . ' is ready.')
        ->success()
        ->getDatabaseMessage();
}

public function toBroadcast(object $notifiable): BroadcastMessage
{
    return new BroadcastMessage($this->toDatabase($notifiable));
}
```
For `EnvironmentDown`/`EnvironmentRecovered`/`CertificateExpiring`, `via()` currently returns `(array) config('projects.alerts.channels', ['mail'])` — extend that config default to `['mail', 'database', 'broadcast']` rather than hardcoding, so ops can still tune it per-environment via `config/projects.php`.

### 6. Deployment reference assets (new `deploy/reverb/` dir)
Since there's no existing deploy/infra documentation in the repo, add:
- `deploy/reverb/reverb.service` — systemd unit running `php artisan reverb:start --host=0.0.0.0 --port=8080` with `Restart=always`.
- `deploy/reverb/nginx.conf` — server block proxying `wss://ws.mpr.example` to `127.0.0.1:8080` with the `Upgrade`/`Connection` headers Reverb's WS upgrade needs, plus TLS via existing cert tooling (Let's Encrypt/certbot assumed).
- `deploy/reverb/README.md` — short checklist: point DNS at the VPS, deploy a copy of this codebase (only `.env` + `vendor/` needed, no DB/migrations required since channel auth happens on the main app, not this box), copy `REVERB_APP_ID`/`KEY`/`SECRET` from the main app's `.env`, enable + start the systemd unit, verify with `wscat` or the browser Network tab.

## Verification
- `php artisan tinker` → dispatch one notification (e.g. `PayslipIssued`) to a real `User` and confirm a row lands in the `notifications` table with a Filament-shaped `data` payload.
- Run `npm run build` (or `npm run dev`) + `php artisan serve`, log into the admin panel, open browser devtools Network/WS tab, confirm a `wss://` connection to the configured Reverb host and a successful `/broadcasting/auth` POST for the `App.Modules.Core.Models.User.{id}` channel.
- From `tinker` in a second terminal, send the same notification to the logged-in user and confirm the bell updates and a toast appears without a page reload.
- `php artisan queue:work` (now on `redis`) is running while testing, since all notifications remain `ShouldQueue`.
- Confirm the `PayslipIssued` on-demand-mail-route branch (recipient with no `User` account) still only sends mail and doesn't throw when `via()` checks `instanceof User`.

## Implementation notes

- **8 notifications, not 7.** `ExpenseClaimSubmitted` and `ExpenseClaimDecided` are two separate classes; the original count in this doc's intro was off by one.
- **§3 changed shape entirely.** Reading Filament's actual source (`vendor/filament/filament/resources/views/components/layout/base.blade.php:147-153`) showed it already bundles its own Echo/Pusher asset and auto-instantiates `window.Echo` from `config('filament.broadcasting.echo')` — no custom render hook or `@vite` entry needed on the panels at all. Just `php artisan vendor:publish --tag=filament-config` and fill in the `echo` array.
- **`php artisan install:broadcasting --reverb` failed partway** (its npm step needs a TTY, unavailable in this environment) after publishing `config/broadcasting.php` and `routes/channels.php` but *before* adding any `REVERB_*` vars to `.env`. Finished by hand: generated the app id/key/secret, added the `REVERB_*`/`VITE_REVERB_*` vars, then `php artisan vendor:publish --tag=reverb-config` and `npm install laravel-echo pusher-js` directly.
- **Redis queue needed `predis`, not `phpredis`.** The `redis-server` binary is installed locally but the phpredis PHP extension isn't — `QUEUE_CONNECTION=redis` broke on the first real dispatch ("Class Redis not found"). Added `predis/predis` and set `REDIS_CLIENT=predis`, Laravel's standard pure-PHP fallback.
- **The `notifications` table didn't exist** (the `database` channel was never used before this). Generated via `php artisan make:notifications-table` and moved into `database/migrations/landlord/`, since `User` lives on the landlord connection, not a tenant one.
- **A concurrent session touched the same area.** While this was in progress, a separate Claude Code session/worktree (co-authored commit `587ea95`, since removed) independently hit and fixed a related problem: `routes/channels.php` builds the broadcaster eagerly at boot, so a `.env` with `BROADCAST_CONNECTION=reverb` and no Reverb credentials took down the entire suite under raw `vendor/bin/phpunit` (640 tests, all with the same bootstrap trace). Their fix — pinning `BROADCAST_CONNECTION=null` in `phpunit.xml` — landed on this branch and is complementary to, not in conflict with, the `REVERB_APP_*` vars added here; both are kept.
- **Verified live**, not just by test suite: logged into the admin panel with Puppeteer (headless `shell`, `--disable-gpu`), confirmed the WebSocket connects to Reverb and the private channel `App.Modules.Core.Models.User.{id}` subscription succeeds, dispatched a real `PayslipRejected` notification via `Notification::sendNow()`, drained the queued broadcast job with `queue:work --once`, and watched the exact payload arrive over the socket — the topbar bell's unread count and a toast updated with no page reload. All test rows and queue keys were cleaned up afterward.
