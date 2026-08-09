@php($chapters = $this->chapters())

<x-filament-panels::page>
    @if ($chapters === [])
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No manual chapters are installed yet.
            </p>
        </x-filament::section>
    @else
        {{-- Contents first: the manual is long, and most visits are looking for
             one chapter rather than starting at the beginning. --}}
        <x-filament::section heading="Contents">
            <ol class="list-decimal space-y-1 pl-5 text-sm">
                @foreach ($chapters as $anchor => $chapter)
                    <li>
                        <a href="#{{ $anchor }}" class="text-primary-600 hover:underline dark:text-primary-400">
                            {{ $chapter['title'] }}
                        </a>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        @foreach ($chapters as $anchor => $chapter)
            {{-- scroll-mt clears the sticky topbar when arriving from the contents. --}}
            <div id="{{ $anchor }}" class="scroll-mt-24">
                <x-filament::section :heading="$chapter['title']">
                    {{-- .help-content styles Str::markdown()'s bare output; see
                         resources/css/filament/admin/theme.css. Shared with the
                         per-screen help panel so both read identically. --}}
                    <div class="help-content text-sm text-gray-700 dark:text-gray-200">
                        {!! $chapter['html'] !!}
                    </div>
                </x-filament::section>
            </div>
        @endforeach
    @endif
</x-filament-panels::page>
