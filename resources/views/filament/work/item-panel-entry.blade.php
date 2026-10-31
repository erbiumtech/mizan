{{-- One line of the item panel's activity: a comment, or a change from the audit trail. --}}
<li class="fi-wip-entry">
    <span class="fi-board-avatar" aria-hidden="true">{{ $entry['initials'] }}</span>
    <div>
        @if ($entry['kind'] === 'comment')
            <p class="fi-wip-entry-head"><strong>{{ $entry['author'] }}</strong> <span class="fi-wip-muted">{{ $entry['at']?->diffForHumans() }}</span></p>
            <p class="fi-wip-entry-body">{!! nl2br(e($entry['body'])) !!}</p>
        @else
            <p class="fi-wip-entry-head">
                <strong>{{ $entry['who'] }}</strong>
                @if ($entry['event'] === 'created')
                    {{ __('created the item') }}
                @elseif ($entry['fields'] !== [])
                    {{ __('changed') }} {{ implode(', ', $entry['fields']) }}
                @else
                    {{ __('updated the item') }}
                @endif
                <span class="fi-wip-muted">{{ $entry['at']?->diffForHumans() }}</span>
            </p>
        @endif
    </div>
</li>
