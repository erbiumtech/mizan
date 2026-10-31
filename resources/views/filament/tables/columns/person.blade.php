{{--
    A person in a list, as the Material list design draws one — docs/material-3-theme.md, "Lists".

    A 36px tonal circle with their initials, then the name over the company email. Initials only: a photo
    would need a URL for a private file, and the list would load one per row. The column it renders keeps
    its own search and sort (EmployeesTable), so this view only draws.
--}}
@php
    $record = $getRecord();
    $name = trim((string) ($record->user?->name ?? $record->name ?? ''));
    $email = $record->user?->email;
    $initials = collect(preg_split('/\s+/u', $name) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp
<div class="fi-md-person">
    <span class="fi-md-person-avatar" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
    <span class="fi-md-person-text">
        <span class="fi-md-person-name">{{ $name !== '' ? $name : '—' }}</span>
        @if (filled($email))
            <span class="fi-md-person-meta">{{ $email }}</span>
        @endif
    </span>
</div>
