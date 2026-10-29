{{--
    Confirm your details — docs/saas-launch-plan.md §14.8.

    One card per check the company requires, each saying where this person stands and
    what to do next. The value is shown partly hidden (ConfirmDetails::shown) so that
    somebody can recognise their own number without reading it off a shared screen.
--}}
<x-filament-panels::page>
    @php($checks = $this->checks())
    @php($others = $this->otherOutstanding())

    @if ($checks === [] && $others === [])
        <x-filament::section>
            <x-slot name="heading">Nothing to confirm</x-slot>
            <p class="text-sm">
                This company does not ask its staff to confirm anything, or you have already
                confirmed everything it asks for. Nothing here needs your attention.
            </p>
        </x-filament::section>
    @endif

    @foreach ($checks as $check)
        <x-filament::section>
            <x-slot name="heading">{{ $check['label'] }}</x-slot>

            <x-slot name="description">
                @if ($check['state'] === 'confirmed')
                    Confirmed.
                @elseif ($check['state'] === 'nothing-on-file')
                    {{ $check['note'] }}
                @else
                    Not confirmed yet.
                @endif
            </x-slot>

            <p class="text-sm font-medium">{{ $check['value'] ?? 'Nothing on your record' }}</p>

            @if ($check['state'] !== 'nothing-on-file')
                <div class="mt-4 flex flex-wrap gap-3">
                    {{ ($this->sendCodeAction)(['kind' => $check['kind']]) }}
                    {{ ($this->enterCodeAction)(['kind' => $check['kind']]) }}
                </div>
            @endif
        </x-filament::section>
    @endforeach

    @if ($others !== [])
        <x-filament::section>
            <x-slot name="heading">Also outstanding</x-slot>
            <x-slot name="description">These are not something you can finish on this screen.</x-slot>

            <ul class="text-sm list-disc ps-5 space-y-1">
                @foreach ($others as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
