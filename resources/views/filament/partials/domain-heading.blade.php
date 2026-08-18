{{--
    3a's column header: which domain is open, and how much is in it.

    Rendered through SIDEBAR_NAV_START, so it sits above the groups and below the company switcher.
    Without it the column is a list of groups with nothing saying what the list is — which is
    readable when the rail is a habit and confusing on the first day.

    The count is of destinations rather than groups: "14" answers "is what I want in here", which is
    the question the rail leaves you holding.
--}}
@php
    $key = \App\Support\NavigationDomains::current();
    $domain = \App\Support\NavigationDomains::definition($key);

    $isReports = $key === 'reports'
        && \App\Modules\Core\Filament\Pages\Reports::canAccess();

    $destinations = $isReports
        ? \App\Modules\Core\Filament\Pages\Reports::total()
        : collect(filament()->getNavigation())
            ->flatMap(fn ($group) => collect($group->getItems()))
            ->count();
@endphp

<div class="fi-domain-heading">
    <span class="fi-domain-heading-label">{{ $domain['label'] }}</span>

    @if ($destinations > 1)
        <span class="fi-domain-heading-count">
            {{ $isReports
                ? trans_choice(':count report|:count reports', $destinations)
                : trans_choice(':count screen|:count screens', $destinations) }}
        </span>
    @endif
</div>

{{--
    In the Reports domain the column carries the report categories, as 3a draws it. The single
    "Reports" navigation item below would then say the same thing as "All reports" at the top of
    this list, so the theme hides the group when these are present.
--}}
@if ($isReports)
    @include('filament.partials.report-categories')
@endif
