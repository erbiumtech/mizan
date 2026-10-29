{{--
    One Geolocation reading, into the field's state as {latitude, longitude, accuracy_m} — or null when the
    browser refuses, times out, or cannot. The person can submit either way: a punch without a position is
    still a punch (docs/saas-launch-plan.md §7.1), and the text below says which of the two is happening.
--}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle('{{ $getStatePath() }}'),
            status: 'asking',
            read() {
                if (! navigator.geolocation) { this.status = 'unsupported'; this.state = null; return }
                navigator.geolocation.getCurrentPosition(
                    (p) => {
                        this.state = {
                            latitude: Number(p.coords.latitude.toFixed(7)),
                            longitude: Number(p.coords.longitude.toFixed(7)),
                            accuracy_m: Math.round(p.coords.accuracy),
                        }
                        this.status = 'ok'
                    },
                    () => { this.state = null; this.status = 'refused' },
                    { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
                )
            },
        }"
        x-init="read()"
        class="text-sm"
    >
        <p x-show="status === 'asking'" class="text-gray-500 dark:text-gray-400">Reading your location…</p>
        <p x-show="status === 'ok'">Location captured, to within <span x-text="state ? state.accuracy_m : ''"></span> m.</p>
        <p x-show="status === 'refused'" class="text-gray-500 dark:text-gray-400">No location: the browser refused or timed out. The time is recorded without one.</p>
        <p x-show="status === 'unsupported'" class="text-gray-500 dark:text-gray-400">This browser cannot read a location. The time is recorded without one.</p>
    </div>
</x-dynamic-component>
