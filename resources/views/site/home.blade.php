@extends('site.layout')
@section('body')
    <h1>{{ $settings['tagline'] }}</h1>
    <p class="lede">{{ $settings['intro'] }}</p>
    <p>
        <a class="btn" href="{{ route('signup') }}">Start a {{ \App\Support\Plans::trialDays() }}-day trial</a>
        <a class="btn ghost" href="{{ route('site.pricing') }}" style="margin-left:.5rem">See pricing</a>
    </p>
    <p class="residency">No card needed. {{ $residency }}</p>

    <h2>What is in it</h2>
    <div class="grid">
        @foreach($modules as $module)
            <div class="card">
                <h3><a href="{{ route('site.feature', ['module' => $module['key']]) }}">{{ $module['label'] }}</a></h3>
                <p class="muted" style="margin:0">{{ $module['description'] }}</p>
            </div>
        @endforeach
    </div>
    <p class="muted" style="margin-top:.75rem"><a href="{{ route('site.features') }}">Every module, and the plan that includes it →</a></p>

    <h2>Plans</h2>
    <div class="grid">
        @foreach($plans as $plan)
            <div class="card @if($plan['is_trial']) trial @endif">
                <h3>{{ $plan['label'] }}</h3>
                <p class="muted" style="margin:0">{{ $plan['description'] }}</p>
                <p class="price">{{ $currency }} {{ number_format($plan['price']) }} <small>per employee per month</small></p>
            </div>
        @endforeach
    </div>
    <p class="muted" style="margin-top:.75rem"><a href="{{ route('site.pricing') }}">Pricing in detail, add-ons and other currencies →</a></p>
@endsection
