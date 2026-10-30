{{--
    How far through the hiring a candidate is — `App\Modules\Recruitment\Support\Pipeline`.

    Takes a state array of `steps`, `ended` and `reason`. Everything it draws is decided in PHP: the
    view asks no questions of the record, which is what keeps the same five steps identical here and
    anywhere else that ever draws them.

    An ordered list rather than a row of divs, because that is what it is — five things in an order,
    with exactly one of them where you are. A screen reader gets "3 of 5" from the markup for free.
--}}
@php($state = $getState())

<div class="fi-hiring-pipeline-wrap">
    <ol class="fi-hiring-pipeline">
        @foreach ($state['steps'] as $step)
            <li @class([
                'fi-hiring-step',
                'fi-done' => $step['state'] === \App\Modules\Recruitment\Support\Pipeline::DONE,
                'fi-current' => $step['state'] === \App\Modules\Recruitment\Support\Pipeline::CURRENT,
                'fi-ended' => $state['ended'],
            ])>
                <span class="fi-hiring-step-marker" aria-hidden="true">
                    @if ($step['state'] === \App\Modules\Recruitment\Support\Pipeline::DONE)
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                    @else
                        {{ $step['number'] }}
                    @endif
                </span>
                <span class="fi-hiring-step-label">{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>

    {{-- The line going cold needs saying in words too; a greyed-out row is not a reason. --}}
    @if ($state['ended'])
        <p class="fi-hiring-ended">
            <span class="fi-hiring-ended-badge">{{ $state['stageLabel'] }}</span>
            @if (filled($state['reason']))
                {{ $state['reason'] }}
            @endif
        </p>
    @endif
</div>
