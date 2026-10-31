@extends('site.layout')
@section('body')
    <p class="muted"><a href="{{ route('site.features') }}">← All features</a></p>
    <h1>{{ $module['label'] }}</h1>
    <p class="lede">{{ $module['description'] }}</p>

    <div class="card">
        @if($module['add_on'])
            <p style="margin:0"><strong>Sold as</strong> the <a href="{{ route('site.pricing') }}#add-on-{{ $module['plan'] }}">{{ $module['plan_label'] }}</a>, on top of {{ $module['requires_plan_label'] }} or above.</p>
        @else
            <p style="margin:0"><strong>Included in</strong> <a href="{{ route('site.pricing') }}#plan-{{ $module['plan'] }}">{{ $module['plan_label'] }}</a> and every plan above it.</p>
        @endif
        @if($requires !== [])
            <p class="muted" style="margin:.5rem 0 0">Works with
                @foreach($requires as $other)
                    @if($other['sold'])<a href="{{ route('site.feature', ['module' => $other['key']]) }}">{{ $other['label'] }}</a>@else{{ $other['label'] }}@endif{{ $loop->last ? '.' : ',' }}
                @endforeach
            </p>
        @endif
    </div>

    <p style="margin-top:1.5rem"><a class="btn" href="{{ route('signup') }}">Start a {{ \App\Support\Plans::trialDays() }}-day trial of {{ \App\Support\Plans::label(\App\Support\Plans::trialPlan()) }}</a></p>
    @unless($module['in_trial'])
        <p class="muted">The trial starts on {{ \App\Support\Plans::label(\App\Support\Plans::trialPlan()) }}; {{ $module['label'] }} is {{ $module['add_on'] ? 'an add-on' : 'on '.$module['plan_label'] }}, switched on when you subscribe.</p>
    @endunless
@endsection
