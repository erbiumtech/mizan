{{--
    The item panel a board card opens — one work item, read and worked on without leaving the board.

    Takes `$panel` from `Board::panel()` (`WorkItemPanel::present()`), already eager-loaded and
    flattened, and decides nothing: every change it offers is a Livewire call that goes through the
    same doors as the form and the board — `moveTo()` for the status, the item's own save for the
    assignee and priority, `WorkItems::create()` for a subtask. The full page is one click away for
    everything else (custom fields, attachments, time, the AI assists).

    Two columns as the item view everyone knows: the work on the left (title, description, subtasks,
    links, the activity), its facts on the right (status, details, development, more fields).
--}}
@if ($panel)
    @php($all = collect($panel['comments'])->map(fn ($c) => ['kind' => 'comment'] + $c)
        ->concat(collect($panel['history'])->map(fn ($h) => ['kind' => 'history'] + $h))
        ->sortByDesc(fn ($e) => $e['at']?->getTimestamp() ?? 0)
        ->values())
    <div
        class="fi-wip-backdrop"
        wire:key="item-panel-{{ $panel['id'] }}"
        x-data
        x-on:click.self="$wire.closeItem()"
        x-on:keydown.escape.window="$wire.closeItem()"
    >
        <section class="fi-wip" role="dialog" aria-modal="true" aria-labelledby="fi-wip-title">
            <header class="fi-wip-head">
                <nav class="fi-wip-crumbs" aria-label="{{ __('Breadcrumbs') }}">
                    @if ($panel['parent'])
                        <button type="button" class="fi-wip-crumb" wire:click="openItem({{ $panel['parent']['id'] }})" title="{{ $panel['parent']['title'] }}">{{ $panel['parent']['key'] }}</button>
                        <span aria-hidden="true">/</span>
                    @endif
                    @include('filament.work.type-icon', ['type' => $panel['type']])
                    <a href="{{ $panel['url'] }}" class="fi-wip-crumb">{{ $panel['key'] }}</a>
                </nav>

                <span class="fi-wip-spacer"></span>

                <button
                    type="button"
                    @class(['fi-wip-icon-button', 'fi-on' => $panel['watching']])
                    wire:click="watchItem"
                    @disabled($panel['watching'])
                    title="{{ $panel['watching'] ? __('You are watching this item') : __('Watch') }}"
                >
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    <span class="fi-wip-count">{{ $panel['watchers'] }}</span>
                </button>
                <button
                    type="button"
                    class="fi-wip-icon-button"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard?.writeText(window.location.href); copied = true; setTimeout(() => copied = false, 1500)"
                    title="{{ __('Copy link') }}"
                    aria-label="{{ __('Copy link') }}"
                >
                    <svg x-show="! copied" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"></path></svg>
                    <svg x-show="copied" x-cloak viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m5 12 5 5 9-10"></path></svg>
                </button>
                <a href="{{ $panel['url'] }}" class="fi-wip-icon-button" title="{{ __('Open the full page') }}" aria-label="{{ __('Open the full page') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 4h6v6M20 4l-8 8M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path></svg>
                </a>
                <button type="button" class="fi-wip-icon-button" wire:click="closeItem" title="{{ __('Close') }}" aria-label="{{ __('Close') }}">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"></path></svg>
                </button>
            </header>

            <div class="fi-wip-body">
                {{-- The work. --}}
                <div class="fi-wip-main">
                    <h2 id="fi-wip-title" class="fi-wip-title">{{ $panel['title'] }}</h2>

                    <details class="fi-wip-section" open>
                        <summary>{{ __('Key details') }}</summary>

                        <h3 class="fi-wip-label">{{ __('Description') }}</h3>
                        @if (filled($panel['description']))
                            <div class="fi-wip-description">{!! \Illuminate\Support\Str::markdown($panel['description'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                        @else
                            <p class="fi-wip-muted">{{ __('No description yet.') }}</p>
                        @endif

                        @if ($panel['can_have_subtasks'])
                            <h3 class="fi-wip-label">
                                {{ __('Subtasks') }}
                                @if ($panel['subtasks'] !== [])
                                    <span class="fi-wip-muted">{{ $panel['subtasks_done'] }}/{{ count($panel['subtasks']) }} {{ __('done') }}</span>
                                @endif
                            </h3>
                            @if ($panel['subtasks'] !== [])
                                <div class="fi-wip-progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($panel['subtasks']) }}" aria-valuenow="{{ $panel['subtasks_done'] }}">
                                    <span style="width: {{ round($panel['subtasks_done'] / max(1, count($panel['subtasks'])) * 100) }}%"></span>
                                </div>
                                <ul class="fi-wip-rows">
                                    @foreach ($panel['subtasks'] as $sub)
                                        <li>
                                            <button type="button" class="fi-wip-row" wire:click="openItem({{ $sub['id'] }})">
                                                @include('filament.work.type-icon', ['type' => 'subtask'])
                                                <span class="fi-wip-row-key">{{ $sub['key'] }}</span>
                                                <span class="fi-wip-row-title">{{ $sub['title'] }}</span>
                                                @if ($sub['initials'] !== '')
                                                    <span class="fi-board-avatar">{{ $sub['initials'] }}</span>
                                                @endif
                                                <span @class(['fi-wip-lozenge', 'fi-wip-lozenge-'.str_replace('_', '-', (string) $sub['category'])])>{{ $sub['status'] }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($panel['can_create'])
                                <form class="fi-wip-inline-form" wire:submit="addSubtask">
                                    <input type="text" wire:model="subtask" maxlength="255" placeholder="{{ __('Add subtask') }}" aria-label="{{ __('Add subtask') }}" />
                                    <button type="submit" class="fi-wip-text-button">{{ __('Add') }}</button>
                                </form>
                            @endif
                        @endif

                        <h3 class="fi-wip-label">{{ __('Linked work items') }}</h3>
                        @if ($panel['links'] !== [])
                            <ul class="fi-wip-rows">
                                @foreach ($panel['links'] as $link)
                                    <li>
                                        <button type="button" class="fi-wip-row" wire:click="openItem({{ $link['id'] }})">
                                            <span class="fi-wip-row-relation">{{ $link['relation'] }}</span>
                                            <span class="fi-wip-row-key">{{ $link['key'] }}</span>
                                            <span class="fi-wip-row-title">{{ $link['title'] }}</span>
                                            <span @class(['fi-wip-lozenge', 'fi-wip-lozenge-'.str_replace('_', '-', (string) $link['category'])])>{{ $link['status'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="fi-wip-muted">{{ __('Nothing linked. Links are added on the full page.') }}</p>
                        @endif
                    </details>

                    {{-- Activity: everything, the conversation, the audit trail, the time. --}}
                    <section class="fi-wip-activity" x-data="{ tab: 'all' }">
                        <h3 class="fi-wip-heading">{{ __('Activity') }}</h3>
                        <div class="fi-wip-tabs" role="tablist" aria-label="{{ __('Activity') }}">
                            @foreach (array_filter(['all' => __('All'), 'comments' => __('Comments'), 'history' => __('History'), 'worklog' => $panel['time_available'] ? __('Work log') : null]) as $key => $label)
                                <button
                                    type="button"
                                    role="tab"
                                    class="fi-wip-tab"
                                    x-bind:aria-selected="(tab === '{{ $key }}').toString()"
                                    x-bind:class="{ 'fi-selected': tab === '{{ $key }}' }"
                                    x-on:click="tab = '{{ $key }}'"
                                >{{ $label }}</button>
                            @endforeach
                        </div>

                        <form class="fi-wip-comment-form" wire:submit="addComment" x-show="tab === 'all' || tab === 'comments'">
                            <span class="fi-board-avatar" aria-hidden="true">{{ \App\Modules\Work\Services\WorkItemPanel::initials(auth()->user()?->name) }}</span>
                            <div class="fi-wip-comment-box">
                                <textarea wire:model="comment" rows="2" maxlength="10000" placeholder="{{ __('Add a comment…') }}" aria-label="{{ __('Add a comment…') }}"></textarea>
                                <div class="fi-wip-comment-actions">
                                    <button type="submit" class="fi-wip-filled-button">{{ __('Save') }}</button>
                                </div>
                            </div>
                        </form>

                        <ol class="fi-wip-feed" x-show="tab === 'all'">
                            @forelse ($all as $entry)
                                @include('filament.work.item-panel-entry', ['entry' => $entry])
                            @empty
                                <li class="fi-wip-muted">{{ __('Nothing has happened here yet.') }}</li>
                            @endforelse
                        </ol>

                        <ol class="fi-wip-feed" x-show="tab === 'comments'" x-cloak>
                            @forelse ($panel['comments'] as $comment)
                                @include('filament.work.item-panel-entry', ['entry' => ['kind' => 'comment'] + $comment])
                            @empty
                                <li class="fi-wip-muted">{{ __('No comments yet.') }}</li>
                            @endforelse
                        </ol>

                        <ol class="fi-wip-feed" x-show="tab === 'history'" x-cloak>
                            @forelse ($panel['history'] as $change)
                                @include('filament.work.item-panel-entry', ['entry' => ['kind' => 'history'] + $change])
                            @empty
                                <li class="fi-wip-muted">{{ __('No changes recorded yet.') }}</li>
                            @endforelse
                        </ol>

                        @if ($panel['time_available'])
                            <ol class="fi-wip-feed" x-show="tab === 'worklog'" x-cloak>
                                @forelse ($panel['worklog'] as $log)
                                    <li class="fi-wip-entry">
                                        <span class="fi-board-avatar" aria-hidden="true">{{ $log['initials'] }}</span>
                                        <div>
                                            <p class="fi-wip-entry-head"><strong>{{ $log['who'] }}</strong> {{ __('logged') }} {{ intdiv($log['minutes'], 60) }}h {{ $log['minutes'] % 60 }}m <span class="fi-wip-muted">· {{ $log['date'] }}</span></p>
                                            @if (filled($log['note']))
                                                <p class="fi-wip-entry-body">{{ $log['note'] }}</p>
                                            @endif
                                        </div>
                                    </li>
                                @empty
                                    <li class="fi-wip-muted">{{ __('No time logged. Log time on the full page.') }}</li>
                                @endforelse
                            </ol>
                        @endif
                    </section>
                </div>

                {{-- The facts. --}}
                <aside class="fi-wip-side">
                    <label @class(['fi-wip-status', 'fi-wip-status-'.str_replace('_', '-', (string) $panel['category'])])>
                        <span class="sr-only">{{ __('Status') }}</span>
                        <select
                            wire:change="setItemStatus($event.target.value)"
                            @disabled(! $panel['can_move'])
                        >
                            @foreach ($panel['statuses'] as $status)
                                <option value="{{ $status['id'] }}" @selected($status['id'] === $panel['status_id'])>{{ $status['name'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <section class="fi-wip-card">
                        <h3 class="fi-wip-card-title">{{ __('Details') }}</h3>
                        <dl class="fi-wip-facts">
                            <dt>{{ __('Assignee') }}</dt>
                            <dd>
                                @if ($panel['assignee_name'])
                                    <span class="fi-wip-person"><span class="fi-board-avatar" aria-hidden="true">{{ $panel['assignee_initials'] }}</span>{{ $panel['assignee_name'] }}</span>
                                @else
                                    <span class="fi-wip-person fi-wip-muted"><span class="fi-board-avatar fi-empty" aria-hidden="true"></span>{{ __('Unassigned') }}</span>
                                @endif
                                @if ($panel['can_update'] && $panel['can_take'] && ! $panel['is_mine'])
                                    <button type="button" class="fi-wip-link-button" wire:click="assignToMe">{{ __('Assign to me') }}</button>
                                @endif
                            </dd>

                            <dt>{{ __('Labels') }}</dt>
                            <dd>
                                @forelse ($panel['labels'] as $label)
                                    <span class="fi-board-chip fi-board-label">{{ $label['name'] }}</span>
                                @empty
                                    <span class="fi-wip-muted">{{ __('None') }}</span>
                                @endforelse
                            </dd>

                            <dt>{{ __('Priority') }}</dt>
                            <dd>
                                @include('filament.work.priority-icon', ['priority' => $panel['priority']])
                                @if ($panel['can_update'])
                                    <select class="fi-wip-inline-select" wire:change="setItemPriority($event.target.value)" aria-label="{{ __('Priority') }}">
                                        @foreach ($panel['priorities'] as $value => $label)
                                            <option value="{{ $value }}" @selected(strtolower((string) $value) === strtolower($panel['priority']))>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    {{ __(ucfirst($panel['priority'])) }}
                                @endif
                            </dd>

                            <dt>{{ __('Parent') }}</dt>
                            <dd>
                                @if ($panel['parent'])
                                    <button type="button" class="fi-wip-link-button" wire:click="openItem({{ $panel['parent']['id'] }})">{{ $panel['parent']['key'] }} {{ $panel['parent']['title'] }}</button>
                                @else
                                    <span class="fi-wip-muted">{{ __('None') }}</span>
                                @endif
                            </dd>

                            @if ($panel['sprint'])
                                <dt>{{ __('Sprint') }}</dt>
                                <dd>{{ $panel['sprint'] }}</dd>
                            @endif

                            @if ($panel['due_on'])
                                <dt>{{ __('Due') }}</dt>
                                <dd @class(['fi-wip-overdue' => $panel['overdue']])>{{ $panel['due_on'] }}</dd>
                            @endif

                            <dt>{{ __('Reporter') }}</dt>
                            <dd>
                                @if ($panel['reporter'])
                                    <span class="fi-wip-person"><span class="fi-board-avatar" aria-hidden="true">{{ $panel['reporter_initials'] }}</span>{{ $panel['reporter'] }}</span>
                                @else
                                    <span class="fi-wip-muted">{{ __('None') }}</span>
                                @endif
                            </dd>
                        </dl>
                    </section>

                    <details class="fi-wip-card" open>
                        <summary class="fi-wip-card-title">{{ __('Development') }}</summary>
                        @if ($panel['branches'] === [] && $panel['commits'] === [] && $panel['pull_requests'] === [])
                            <p class="fi-wip-muted">{{ __('No branch, commit or pull request names this item yet. Create a branch from the full page.') }}</p>
                        @else
                            <ul class="fi-wip-dev">
                                @foreach ($panel['pull_requests'] as $pr)
                                    <li>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="6" cy="6" r="2"></circle><circle cx="6" cy="18" r="2"></circle><circle cx="18" cy="18" r="2"></circle><path d="M6 8v8M18 16V9a3 3 0 0 0-3-3h-3"></path></svg>
                                        <a href="{{ $pr['url'] }}" target="_blank" rel="noopener">#{{ $pr['number'] }} {{ $pr['title'] }}</a>
                                        <span @class(['fi-wip-lozenge', 'fi-wip-lozenge-done' => $pr['state'] === 'merged', 'fi-wip-lozenge-in-progress' => $pr['state'] === 'open'])>{{ __(ucfirst((string) $pr['state'])) }}</span>
                                    </li>
                                @endforeach
                                @foreach ($panel['branches'] as $branch)
                                    <li @class(['fi-wip-muted' => $branch['deleted']])>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="6" cy="5" r="2"></circle><circle cx="6" cy="19" r="2"></circle><circle cx="18" cy="7" r="2"></circle><path d="M6 7v10M18 9c0 5-6 4-12 8"></path></svg>
                                        @if ($branch['url'])
                                            <a href="{{ $branch['url'] }}" target="_blank" rel="noopener">{{ $branch['name'] }}</a>
                                        @else
                                            <span>{{ $branch['name'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                                @if ($panel['commits'] !== [])
                                    <li>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M3 12h6M15 12h6"></path></svg>
                                        <span>{{ trans_choice(':count commit|:count commits', count($panel['commits'])) }}</span>
                                    </li>
                                    @foreach (array_slice($panel['commits'], 0, 3) as $commit)
                                        <li class="fi-wip-commit">
                                            <code>{{ $commit['sha'] }}</code>
                                            <span>{{ $commit['message'] }}</span>
                                        </li>
                                    @endforeach
                                @endif
                            </ul>
                        @endif
                    </details>

                    <details class="fi-wip-card">
                        <summary class="fi-wip-card-title">{{ __('More fields') }}</summary>
                        <dl class="fi-wip-facts">
                            <dt>{{ __('Type') }}</dt>
                            <dd>{{ __(ucfirst($panel['type'])) }}</dd>
                            <dt>{{ __('Release') }}</dt>
                            <dd>{{ $panel['release'] ?? __('None') }}</dd>
                            <dt>{{ __('Points') }}</dt>
                            <dd>{{ $panel['points'] ?? __('None') }}</dd>
                            <dt>{{ __('Estimate') }}</dt>
                            <dd>
                                @if ($panel['estimate_minutes'])
                                    {{ intdiv($panel['estimate_minutes'], 60) }}h {{ $panel['estimate_minutes'] % 60 }}m
                                @else
                                    {{ __('None') }}
                                @endif
                            </dd>
                            @if ($panel['resolution'])
                                <dt>{{ __('Resolution') }}</dt>
                                <dd>{{ $panel['resolution'] }}</dd>
                            @endif
                        </dl>
                    </details>

                    <p class="fi-wip-stamps">
                        @if ($panel['created'])
                            <span>{{ __('Created :when', ['when' => $panel['created']->diffForHumans()]) }}</span>
                        @endif
                        @if ($panel['updated'])
                            <span>{{ __('Updated :when', ['when' => $panel['updated']->diffForHumans()]) }}</span>
                        @endif
                        @if ($panel['resolved'])
                            <span>{{ __('Resolved :when', ['when' => $panel['resolved']->diffForHumans()]) }}</span>
                        @endif
                    </p>
                </aside>
            </div>
        </section>
    </div>
@endif
