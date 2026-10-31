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

    {{--
        A line for the site diary, with a photograph if there is one — docs/desktop-mobile-plan.md O2. Shown only
        when the panel has told this browser which jobs the signed-in person may write a diary for; the line is
        kept in the outbox and sent when the network is back, and the photograph is uploaded after it.
    --}}
    <div class="card" id="offline-diary" hidden>
        <p class="muted">{{ __('You can still add a line to the site diary. It is kept on this device and sent, with its photo, as soon as you are back online.') }}</p>
        <form id="offline-diary-form">
            <p>
                <label for="offline-diary-job">{{ __('Job') }}</label><br>
                <select id="offline-diary-job" required></select>
            </p>
            <p>
                <label for="offline-diary-kind">{{ __('Kind') }}</label><br>
                <select id="offline-diary-kind" required>
                    @foreach (\App\Modules\ConstructionField\Models\DailyLogEvent::KINDS as $kind => $label)
                        <option value="{{ $kind }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </p>
            <p>
                <label for="offline-diary-description">{{ __('What happened') }}</label><br>
                <textarea id="offline-diary-description" rows="3" maxlength="2000" required style="width: 100%"></textarea>
            </p>
            <p>
                <label for="offline-diary-photo">{{ __('Photograph') }}</label><br>
                <input type="file" id="offline-diary-photo" accept="image/*" capture="environment">
            </p>
            <button type="submit" id="offline-diary-button">{{ __('Save to the diary') }}</button>
        </form>
        <p class="muted" id="offline-diary-status" aria-live="polite"></p>
    </div>

    @include('pwa.outbox-script')
    <script>
        (function () {
            const sync = window.MizanSync;
            const card = document.getElementById('offline-diary');
            const form = document.getElementById('offline-diary-form');
            const jobs = document.getElementById('offline-diary-job');
            const status = document.getElementById('offline-diary-status');
            const context = sync && sync.context();

            if (!context || !context.endpoints || !context.endpoints['site-diary'] || !(context.diaryJobs || []).length) {
                return;
            }

            context.diaryJobs.forEach((job) => {
                const option = document.createElement('option');
                option.value = job.id;
                option.textContent = job.label;
                jobs.appendChild(option);
            });

            card.hidden = false;

            const words = {
                waiting: @js(__('Waiting to sync: :lines lines, :photos photos')),
                saved: @js(__('Saved on this device.')),
            };

            function today() {
                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');

                return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
            }

            function time() {
                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');

                return pad(now.getHours()) + ':' + pad(now.getMinutes());
            }

            async function refresh() {
                const [lines, photos] = await Promise.all([sync.pending('site-diary'), sync.pendingBlobs('site-diary')]);
                status.textContent = lines.length || photos.length
                    ? words.waiting.replace(':lines', lines.length).replace(':photos', photos.length)
                    : '';
            }

            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const description = document.getElementById('offline-diary-description').value.trim();
                const photo = document.getElementById('offline-diary-photo').files[0];

                if (!description) {
                    return;
                }

                const payload = {
                    type: 'entry',
                    job_id: Number(jobs.value),
                    log_date: today(),
                    kind: document.getElementById('offline-diary-kind').value,
                    description,
                    started_at: time(),
                };

                const files = photo ? [{ file: photo, caption: description.slice(0, 255) }] : [];

                await sync.enqueue('site-diary', payload, new Date().toISOString(), files);
                form.reset();
                status.textContent = words.saved;
                window.setTimeout(refresh, 1500);
            });

            window.addEventListener('mizan-sync', refresh);
            refresh();
        })();
    </script>
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
