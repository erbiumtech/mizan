@extends('site.layout')
@section('body')
    <p class="muted"><a href="{{ route('site.features') }}">← All features</a></p>
    <h1>{{ $module['label'] }}</h1>
    <p class="lede">{{ $module['description'] }}</p>

    <div class="card">
        <p style="margin:0"><strong>Included in</strong> <a href="{{ route('site.pricing') }}#plan-{{ $module['plan'] }}">{{ $module['plan_label'] }}</a> and every plan above it.</p>
        @if($requires !== [])
            <p class="muted" style="margin:.5rem 0 0">Works with
                @foreach($requires as $other)
                    @if($other['sold'])<a href="{{ route('site.feature', ['module' => $other['key']]) }}">{{ $other['label'] }}</a>@else{{ $other['label'] }}@endif{{ $loop->last ? '.' : ',' }}
                @endforeach
            </p>
        @endif
    </div>

    <p style="margin-top:1.5rem"><a class="btn" href="{{ route('signup') }}">Try it on a {{ \App\Support\Plans::trialDays() }}-day trial</a></p>
@endsection
