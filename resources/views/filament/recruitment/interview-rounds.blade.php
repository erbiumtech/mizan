{{--
    The rounds on one application — `App\Modules\Recruitment\Services\Interviews::rounds()`.

    Takes the state that method returns and draws it. There is no round-two special case anywhere
    below: a second round is one more item, which is the whole point of listing them rather than
    showing "the interview" and hiding the rest behind a list filter.

    Empty is a sentence, not an empty card. "No interviews yet" with the reason it is empty tells
    somebody what to do next; a blank box tells them something is broken.
--}}
@php($rounds = $getState()['rounds'])

@if ($rounds === [])
    <p class="fi-hiring-empty">
        No interviews have been scheduled yet. <em>Schedule interview</em> above books the first round.
    </p>
@else
    <ol class="fi-hiring-rounds">
        @foreach ($rounds as $round)
            <li @class(['fi-hiring-round', 'fi-' . $round['outcome']])>
                <div class="fi-hiring-round-head">
                    <span class="fi-hiring-round-name">Round {{ $round['round'] }}</span>
                    <span class="fi-hiring-round-mode">{{ $round['mode'] }}</span>

                    <span class="fi-hiring-round-badges">
                        {{-- Feedback first: on a decided round it is the evidence, on an open one it is what is still missing. --}}
                        @if ($round['feedback']['of'] > 0)
                            <span @class(['fi-hiring-chip', 'fi-waiting' => $round['feedback']['given'] < $round['feedback']['of']])>
                                {{ $round['feedback']['given'] }} of {{ $round['feedback']['of'] }} replied
                                @if ($round['feedback']['average'] !== null)
                                    · {{ number_format($round['feedback']['average'], 1) }}/5
                                @endif
                            </span>
                        @endif

                        <span class="fi-hiring-chip fi-outcome">{{ $round['outcomeLabel'] }}</span>
                    </span>
                </div>

                <p class="fi-hiring-round-when">
                    @if ($round['when'])
                        {{ $round['when']->format('d M Y') }} at {{ $round['when']->format('H:i') }}
                    @else
                        No date set
                    @endif
                    @if (filled($round['location']))
                        · {{ $round['location'] }}
                    @endif
                </p>

                @if ($round['panel'] !== [])
                    <p class="fi-hiring-round-panel">With: {{ implode(', ', $round['panel']) }}</p>
                @endif

                @if (filled($round['notes']))
                    <p class="fi-hiring-round-notes">{{ $round['notes'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
