@extends('layouts.public')

@section('title', __('Offline'))

@section('body')
    <h1>{{ __("You're offline") }}</h1>
    <p class="muted">{{ __('Nothing here is stored on this device; the panel needs a connection. It will load again as soon as you have one.') }}</p>
    <div class="card">
        <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
    </div>

    {{--
        The one thing that works without a connection — docs/desktop-mobile-plan.md O1. Shown only when the
        panel has told this browser that the signed-in person is an employee of a company with attendance;
        the punch is kept in the outbox and sent, in order, when the network is back.
    --}}
    <div class="card" id="offline-punch" hidden>
        <p class="muted">{{ __('You can still clock in or out. The punch is kept on this device and sent as soon as you are back online.') }}</p>
        <button type="button" id="offline-punch-button">{{ __('Clock in / out now') }}</button>
        <p class="muted" id="offline-punch-status" aria-live="polite"></p>
    </div>

    @include('pwa.outbox-script')
    <script>
        (function () {
            const sync = window.MizanSync;
            const card = document.getElementById('offline-punch');
            const button = document.getElementById('offline-punch-button');
            const status = document.getElementById('offline-punch-status');
            const context = sync && sync.context();

            if (!context || !context.employee || !context.endpoints || !context.endpoints.attendance) {
                return;
            }

            card.hidden = false;

            const words = {
                waiting: @js(__('Waiting to sync: :count')),
                saved: @js(__('Punch saved on this device.')),
            };

            function refresh() {
                sync.pending('attendance').then((rows) => {
                    status.textContent = rows.length ? words.waiting.replace(':count', rows.length) : '';
                });
            }

            function position() {
                return new Promise((resolve) => {
                    if (!navigator.geolocation) {
                        resolve(null);

                        return;
                    }

                    const done = (value) => resolve(value);
                    navigator.geolocation.getCurrentPosition(
                        (p) => done({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy_m: Math.round(p.coords.accuracy) }),
                        () => done(null),
                        { timeout: 5000, maximumAge: 60000 }
                    );
                });
            }

            button.addEventListener('click', async () => {
                button.disabled = true;

                try {
                    const where = await position();
                    const payload = Object.assign({ type: 'punch', punched_at: new Date().toISOString() }, where || {});
                    await sync.enqueue('attendance', payload, payload.punched_at);
                    status.textContent = words.saved;
                    window.setTimeout(refresh, 1500);
                } finally {
                    button.disabled = false;
                }
            });

            window.addEventListener('mizan-sync', refresh);
            refresh();
        })();
    </script>
@endsection
