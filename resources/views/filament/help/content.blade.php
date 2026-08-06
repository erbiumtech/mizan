@if ($access ?? null)
    {{-- What the reader themselves may do here, worked out from the policy —
         see App\Filament\Support\HelpAccess. Sits above the prose because the
         complaint that prompted it was that the panel never said which part of
         it was yours. --}}
    <div class="mb-4 rounded-lg bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        @if ($access['role'])
            <p class="mb-2 text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">
                You are signed in as {{ $access['role'] }}
            </p>
        @endif

        @if ($access['can'] !== [])
            <p class="text-sm text-gray-700 dark:text-gray-200">
                <span class="font-semibold text-gray-950 dark:text-white">On this screen you can</span>
                {{ collect($access['can'])->join(', ', ' and ') }}.
            </p>
        @endif

        @foreach ($access['cannot'] as $blocked)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                You cannot <span class="font-medium">{{ $blocked['verb'] }}</span>@if ($blocked['who'] !== []) —
                    that is {{ collect($blocked['who'])->join(', ', ' or ') }}@endif.
            </p>
        @endforeach

        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
            Individual records can still be locked by their own state — a posted entry, a closed month.
        </p>
    </div>
@endif

{{--
    Str::markdown() output has no classes of its own — .help-content is styled
    in resources/css/filament/admin/theme.css, since this project has no
    @tailwindcss/typography plugin to reach for instead.
--}}
<div class="help-content text-sm text-gray-700 dark:text-gray-200">
    {!! $markdown !!}
</div>
