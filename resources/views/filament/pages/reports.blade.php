<x-filament-panels::page>
    @foreach ($this->sections() as $heading => $links)
        <x-filament::section :heading="$heading">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($links as $link)
                    <a
                        href="{{ $link['url'] }}"
                        class="group flex items-start gap-3 rounded-xl border border-gray-200 p-4 transition hover:border-primary-500 hover:bg-gray-50 dark:border-white/10 dark:hover:border-primary-500 dark:hover:bg-white/5"
                    >
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition group-hover:bg-primary-50 group-hover:text-primary-600 dark:bg-white/5 dark:text-gray-400 dark:group-hover:bg-primary-500/10 dark:group-hover:text-primary-400">
                            <x-filament::icon :icon="$link['icon']" class="size-5" />
                        </span>

                        <span class="min-w-0">
                            <span class="block font-medium text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                                {{ $link['label'] }}
                            </span>
                            <span class="mt-0.5 block text-sm text-gray-500 dark:text-gray-400">
                                {{ $link['description'] }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
