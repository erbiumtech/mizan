@props([
    'label',
    'value',
    'color' => 'gray',
    'help' => null,
])

@php
    $valueColors = [
        'gray' => 'text-gray-950 dark:text-white',
        'success' => 'text-success-600 dark:text-success-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'primary' => 'text-primary-600 dark:text-primary-400',
    ];
@endphp

<x-filament::section compact>
    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</span>
    <div class="mt-1 text-2xl font-semibold tracking-tight tabular-nums {{ $valueColors[$color] ?? $valueColors['gray'] }}">
        {{ $value }}
    </div>
    @if($help)
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $help }}</p>
    @endif
</x-filament::section>
