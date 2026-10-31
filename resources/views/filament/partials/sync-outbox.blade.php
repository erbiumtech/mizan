{{--
    The panel's end of the offline outbox — docs/desktop-mobile-plan.md O1.

    Two jobs. It tells the browser what the offline page will need and cannot find out for itself — who is
    signed in, which company, whether they are an employee, where the attendance inbox is, the CSRF token
    the session accepts — and it replays whatever the outbox holds, now and whenever the network returns.
    What was sent is said once, through the panel's own notifications; a refusal carries the server's
    reason; a dead session or an unlicensed module is said plainly and not retried.
--}}
@php
    $tenant = \Filament\Facades\Filament::getTenant();
    $user = auth()->user();
    $attendance = $tenant !== null && \Illuminate\Support\Facades\Route::has('attendance.sync') && modules()->availableTo($user, 'attendance');
    $endpoints = $attendance ? ['attendance' => route('attendance.sync', ['company' => $tenant->slug])] : [];
@endphp
@include('pwa.outbox-script')
<script>
    (function () {
        const sync = window.MizanSync;

        if (!sync) {
            return;
        }

        sync.setContext({
            user: @js($user?->getKey()),
            company: @js($tenant?->slug),
            employee: @js($tenant !== null && app(\App\Support\EmployeeAccess::class)->isEmployee($user)),
            endpoints: @js($endpoints),
            csrf: @js(csrf_token()),
        });

        const words = {
            sent: @js(__('Offline punches sent')),
            sentBody: @js(__(':count punch recorded from this device.|:count punches recorded from this device.')),
            refused: @js(__('An offline punch was refused')),
            session: @js(__('Sign in again to send the punches saved on this device.')),
            module: @js(__('Attendance is not enabled for this company, so the punches saved on this device were not sent.')),
        };

        function plural(sentence, count) {
            const parts = sentence.split('|');

            return (count === 1 ? parts[0] : parts[parts.length - 1]).replace(':count', count);
        }

        function notify(title, body, kind) {
            if (typeof window.FilamentNotification !== 'function') {
                return;
            }

            const notification = new window.FilamentNotification().title(title);

            if (body) {
                notification.body(body);
            }

            notification[kind || 'success']().send();
        }

        if (!window.__mizanSyncListening) {
            window.__mizanSyncListening = true;

            window.addEventListener('mizan-sync', (event) => {
                const summary = event.detail || {};

                if (summary.applied > 0) {
                    notify(words.sent, plural(words.sentBody, summary.applied));
                }

                if (summary.rejected > 0) {
                    const last = sync.state().lastRejected;
                    notify(words.refused, last && last.message, 'warning');
                }

                if (summary.blocked === 'session') {
                    notify(words.session, null, 'warning');
                } else if (summary.blocked === 'module') {
                    notify(words.module, null, 'warning');
                }
            });
        }

        sync.flush();
    })();
</script>
