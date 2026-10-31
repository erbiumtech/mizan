{{--
    The panel's end of the offline outbox — docs/desktop-mobile-plan.md O1, widened in O2.

    Two jobs. It tells the browser what the offline page will need and cannot find out for itself — who is
    signed in, which company, whether they are an employee, which jobs they may write a site diary for, where
    each workflow's inbox is, the CSRF token the session accepts — and it replays whatever the outbox holds,
    now and whenever the network returns. What was sent is said once, through the panel's own notifications;
    a refusal or a snag change that arrived too late carries the server's reason; a dead session or an
    unlicensed module is said plainly and not retried.

    The job list is the one piece of company data this leaves on the device, and it is the least there is: a
    code and a name per live job, so a diary line written offline can say which job it is for. It is fetched
    from its own endpoint after the page loads, at most every ten minutes, rather than rendered here — that
    cost every panel page a query. The outbox script is linked, versioned and cached, not inlined, for the
    same reason in bytes. Everything else the offline page shows is what the person typed.
--}}
@php
    $tenant = \Filament\Facades\Filament::getTenant();
    $user = auth()->user();
    $endpoints = [];
    $diaryJobsUrl = null;

    if ($tenant !== null && \Illuminate\Support\Facades\Route::has('attendance.sync') && modules()->availableTo($user, 'attendance')) {
        $endpoints['attendance'] = route('attendance.sync', ['company' => $tenant->slug]);
    }

    if ($tenant !== null && \Illuminate\Support\Facades\Route::has('construction_field.sync.site-diary') && modules()->availableTo($user, 'construction_field')) {
        $endpoints['punch-items'] = route('construction_field.sync.punch-items', ['company' => $tenant->slug]);

        if ($user?->can('create', \App\Modules\ConstructionField\Models\DailyLog::class)) {
            $endpoints['site-diary'] = route('construction_field.sync.site-diary', ['company' => $tenant->slug]);
            $endpoints['site-diary:blobs'] = route('construction_field.sync.site-diary.blobs', ['company' => $tenant->slug]);
            $diaryJobsUrl = route('construction_field.sync.site-diary.jobs', ['company' => $tenant->slug]);
        }
    }
@endphp
<script src="{{ \App\Http\Controllers\PwaController::outboxUrl() }}"></script>
<script>
    (function () {
        const sync = window.MizanSync;

        if (!sync) {
            return;
        }

        // The job list is not rendered into the page (it cost every page a query); the copy the device
        // already holds is kept while it is the same company's, and refreshed in the background below.
        const previous = sync.context() || {};
        const company = @js($tenant?->slug);
        const sameCompany = previous.company === company;

        sync.setContext({
            user: @js($user?->getKey()),
            company: company,
            employee: @js($tenant !== null && app(\App\Support\EmployeeAccess::class)->isEmployee($user)),
            diaryJobs: sameCompany ? (previous.diaryJobs || []) : [],
            diaryJobsAt: sameCompany ? (previous.diaryJobsAt || 0) : 0,
            endpoints: @js($endpoints),
            csrf: @js(csrf_token()),
        });

        const diaryJobsUrl = @js($diaryJobsUrl);
        const stale = Date.now() - ((sync.context() || {}).diaryJobsAt || 0) > 10 * 60 * 1000;

        if (diaryJobsUrl && stale && navigator.onLine !== false) {
            const refresh = () => fetch(diaryJobsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((body) => {
                    if (body && Array.isArray(body.jobs)) {
                        sync.setContext(Object.assign(sync.context() || {}, { diaryJobs: body.jobs, diaryJobsAt: Date.now() }));
                    }
                })
                .catch(() => {});

            (window.requestIdleCallback || ((fn) => window.setTimeout(fn, 1500)))(refresh);
        }

        const words = {
            sent: @js(__('Offline punches sent')),
            sentBody: @js(__(':count punch recorded from this device.|:count punches recorded from this device.')),
            diarySent: @js(__('Offline diary lines sent')),
            diarySentBody: @js(__(':count line added to the site diary from this device.|:count lines added to the site diary from this device.')),
            snagsSent: @js(__('Offline snag changes sent')),
            snagsSentBody: @js(__(':count snag changed from this device.|:count snags changed from this device.')),
            photosSent: @js(__(':count photo from this device attached.|:count photos from this device attached.')),
            refused: @js(__('Something saved on this device was refused')),
            stale: @js(__('An offline snag change was not applied')),
            session: @js(__('Sign in again to send what is saved on this device.')),
            module: @js(__('This company does not have the module this device\'s saved entries need, so they were not sent.')),
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

        function applied(summary, workflow) {
            const counts = (summary.byWorkflow || {})[workflow] || {};

            return counts.applied || 0;
        }

        if (!window.__mizanSyncListening) {
            window.__mizanSyncListening = true;

            window.addEventListener('mizan-sync', (event) => {
                const summary = event.detail || {};

                if (applied(summary, 'attendance') > 0) {
                    notify(words.sent, plural(words.sentBody, applied(summary, 'attendance')));
                }

                if (applied(summary, 'site-diary') > 0) {
                    notify(words.diarySent, plural(words.diarySentBody, applied(summary, 'site-diary')));
                }

                if (applied(summary, 'punch-items') > 0) {
                    notify(words.snagsSent, plural(words.snagsSentBody, applied(summary, 'punch-items')));
                }

                if (summary.photos > 0) {
                    notify(plural(words.photosSent, summary.photos));
                }

                if (summary.stale > 0) {
                    const last = sync.state().lastRejected;
                    notify(words.stale, last && last.message, 'warning');
                } else if (summary.rejected > 0 || summary.photosRefused > 0) {
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
