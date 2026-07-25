<?php

namespace App\Filament\CommandPalette;

interface PaletteProvider
{
    /**
     * Return matching palette items for the query. Each item is an array:
     *   ['group' => string, 'label' => string, 'subtitle' => ?string,
     *    'url' => ?string, 'score' => int]
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(string $query): array;
}
