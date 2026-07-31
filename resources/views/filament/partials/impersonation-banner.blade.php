@php($impersonation = app(App\Support\Impersonation::class))

@if($impersonation->isActive())
    @php($impersonator = $impersonation->impersonator())

    {{-- Deliberately loud and at the very top of the page: acting as somebody else
         without realising it is the whole risk of this feature. --}}
    <div class="fi-impersonation-banner flex flex-wrap items-center justify-between gap-3 bg-warning-500 px-4 py-2 text-sm font-medium text-white dark:bg-warning-600">
        <span class="flex items-center gap-2">
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5" />

            You are signed in as <strong>{{ auth()->user()?->name }}</strong>
            @if($impersonator)
                — really {{ $impersonator->name }}
            @endif
        </span>

        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit" class="rounded-lg bg-white/20 px-3 py-1 font-semibold hover:bg-white/30">
                Stop impersonating
            </button>
        </form>
    </div>
@endif
