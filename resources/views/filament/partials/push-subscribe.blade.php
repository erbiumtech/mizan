{{--
    Notifications on this device — docs/desktop-mobile-plan.md M2.

    One switch in the user menu, because the question is per device, not per account: a person's phone
    may want pushes and their shared office PC may not. Off: ask the browser for permission, subscribe
    with the installation's VAPID key (the meta tag pwa-head renders), post the subscription to the same
    endpoint API clients use. On: unsubscribe in the browser first, then tell the server, so a device
    the server still lists is never one that is silently unreachable.

    Renders nothing when push is not configured, when the browser cannot push, or when the person has
    refused permission for good — a switch that cannot work is worse than no switch.
--}}
@if (\App\Support\WebPush::isConfigured())
<div
    x-data="{
        supported: false,
        denied: false,
        on: false,
        busy: false,
        key: document.querySelector('meta[name=webpush-public-key]')?.content ?? '',
        csrf: @js(csrf_token()),
        async init() {
            this.supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && this.key !== ''
            if (! this.supported) return
            this.denied = Notification.permission === 'denied'
            try {
                const registration = await navigator.serviceWorker.ready
                const subscription = await registration.pushManager.getSubscription()
                // A subscription this browser holds is On only if the server has it against *this* person.
                // On a shared browser it may be the previous user's: then it is let go, so their notifications
                // stop arriving on a device they are no longer signed in to, and the switch shows Off.
                this.on = subscription !== null && (await this.isMine(subscription.endpoint))
                if (subscription !== null && ! this.on) {
                    await subscription.unsubscribe().catch(() => {})
                }
            } catch (e) { this.supported = false }
        },
        async isMine(endpoint) {
            const response = await fetch(@js(route('push.mine')) + '?endpoint=' + encodeURIComponent(endpoint), {
                headers: { 'Accept': 'application/json' },
            })
            if (! response.ok) return false
            return (await response.json()).mine === true
        },
        toUint8(base64) {
            const padding = '='.repeat((4 - base64.length % 4) % 4)
            const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
            return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
        },
        async toggle() {
            if (this.busy) return
            this.busy = true
            try {
                const registration = await navigator.serviceWorker.ready
                if (this.on) {
                    const subscription = await registration.pushManager.getSubscription()
                    if (subscription) {
                        const endpoint = subscription.endpoint
                        await subscription.unsubscribe()
                        await fetch(@js(route('push.unsubscribe')), {
                            method: 'DELETE',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                            body: JSON.stringify({ endpoint }),
                        })
                    }
                    this.on = false
                } else {
                    if (Notification.permission !== 'granted' && (await Notification.requestPermission()) !== 'granted') {
                        this.denied = Notification.permission === 'denied'
                        return
                    }
                    const subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: this.toUint8(this.key),
                    })
                    // Registered or not at all: a subscription the server never heard of would show On
                    // after a reload and deliver nothing, so a failed or thrown registration lets it go.
                    let registered = false
                    try {
                        const response = await fetch(@js(route('push.subscribe')), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                            body: JSON.stringify(subscription.toJSON()),
                        })
                        registered = response.ok
                    } catch (e) {
                        registered = false
                    }
                    if (! registered) {
                        await subscription.unsubscribe().catch(() => {})
                        return
                    }
                    this.on = true
                }
            } catch (e) {
                // Nothing to show: the switch simply stays where it was.
            } finally {
                this.busy = false
            }
        },
    }"
    x-show="supported && ! denied"
    x-cloak
    class="fi-dropdown-list-item"
>
    <button
        type="button"
        x-on:click="toggle()"
        x-bind:disabled="busy"
        class="fi-dropdown-list-item flex w-full items-center gap-2 whitespace-nowrap rounded-md p-2 text-sm transition-colors duration-75 outline-none hover:bg-gray-50 focus-visible:bg-gray-50 disabled:pointer-events-none disabled:opacity-70 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
    >
        <x-filament::icon
            icon="heroicon-m-bell-alert"
            class="h-5 w-5 text-gray-400 dark:text-gray-500"
        />
        <span class="flex-1 truncate text-start text-gray-700 dark:text-gray-200">
            {{ __('Notifications on this device') }}
        </span>
        <span
            class="text-xs font-medium"
            x-bind:class="on ? 'text-success-600 dark:text-success-400' : 'text-gray-400 dark:text-gray-500'"
            x-text="on ? @js(__('On')) : @js(__('Off'))"
        ></span>
    </button>
</div>
@endif
