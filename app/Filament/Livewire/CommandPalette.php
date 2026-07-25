<?php

namespace App\Filament\Livewire;

use App\Filament\CommandPalette\Providers\CommandProvider;
use App\Filament\CommandPalette\Providers\PageProvider;
use App\Filament\CommandPalette\Providers\RecordProvider;
use App\Filament\CommandPalette\Providers\ResourceProvider;
use Livewire\Component;

/**
 * ⌘K command palette.
 *
 * Rendered globally via a panel render hook. Alpine handles the hotkey, focus,
 * and keyboard navigation; this component collects results from providers,
 * ranks and groups them. Every provider is permission- and tenant-aware, so the
 * palette only surfaces what the current user can reach in the current company.
 */
class CommandPalette extends Component
{
    /** Group display order + per-group cap. */
    protected const GROUP_ORDER = ['Commands', 'Resources', 'Pages', 'Records'];

    protected const PER_GROUP_LIMIT = 8;

    /**
     * @return array<int, array{group: string, items: array<int, array<string, mixed>>}>
     */
    public function search(string $query = ''): array
    {
        $query = trim($query);

        $items = collect($this->providers())
            ->flatMap(fn ($provider) => $provider->items($query));

        return collect(self::GROUP_ORDER)
            ->map(function (string $group) use ($items): ?array {
                $groupItems = $items
                    ->where('group', $group)
                    ->sortByDesc('score')
                    ->take(self::PER_GROUP_LIMIT)
                    ->map(fn (array $item) => [
                        'label' => $item['label'],
                        'subtitle' => $item['subtitle'] ?? null,
                        'url' => $item['url'] ?? null,
                        'command' => $item['command'] ?? null,
                        'icon' => $item['icon'] ?? null,
                    ])
                    ->values()
                    ->all();

                return $groupItems ? ['group' => $group, 'items' => $groupItems] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, \App\Filament\CommandPalette\PaletteProvider>
     */
    protected function providers(): array
    {
        return [
            new CommandProvider,
            new ResourceProvider,
            new PageProvider,
            new RecordProvider,
        ];
    }

    public function render()
    {
        return view('filament.livewire.command-palette');
    }
}
