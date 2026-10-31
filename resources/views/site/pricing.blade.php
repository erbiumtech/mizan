@extends('site.layout')
@section('body')
    <h1>Pricing</h1>
    <p class="lede">Per active employee per month. Every plan starts with a {{ $trialDays }}-day trial of {{ $trialPlan }}, with no card.</p>

    <p class="currencies muted">Show prices in
        @foreach($currencies as $option)
            <a href="{{ route('site.pricing', ['currency' => $option]) }}" class="@if($option === $currency) on @endif">{{ $option }}</a>
        @endforeach
    </p>

    <div class="grid">
        @foreach($plans as $plan)
            <div class="card @if($plan['is_trial']) trial @endif" id="plan-{{ $plan['key'] }}">
                <h3>{{ $plan['label'] }} @if($plan['is_trial'])<span class="pill">the trial plan</span>@endif</h3>
                <p class="muted" style="margin:0">{{ $plan['description'] }}</p>
                <p class="price">{{ $currency }} {{ number_format($plan['price']) }} <small>per employee per month</small></p>
                @if($plan['includes'])<p class="muted" style="margin:.25rem 0 0">Everything in {{ $plan['includes'] }}, plus:</p>@endif
                <ul class="plain">
                    @foreach($plan['modules'] as $module)<li>{{ $module }}</li>@endforeach
                    @if($plan['custom_domain'])<li>Your own domain</li>@endif
                </ul>
                <p style="margin:1rem 0 0"><a class="btn" href="{{ route('signup') }}">Start a trial</a></p>
            </div>
        @endforeach
    </div>

    <p class="muted" style="margin-top:1rem">
        Billed for at least {{ $minimumSeats }} employees a month, whatever the headcount. The trial runs on {{ $trialPlan }}; the plan can be changed from the subscription page, and support can move a company to a smaller plan on request.
    </p>

    @if($addOns !== [])
        <h2>Add-ons</h2>
        <p class="muted">Flat per company per month, on top of the plan they require.</p>
        <div class="grid">
            @foreach($addOns as $addOn)
                <div class="card" id="add-on-{{ $addOn['key'] }}">
                    <h3>{{ $addOn['label'] }}</h3>
                    <p class="muted" style="margin:0">Requires {{ $addOn['requires_plan'] }}.</p>
                    <p class="price">{{ $currency }} {{ number_format($addOn['price']) }} <small>per company per month</small></p>
                    <ul class="plain">
                        @foreach($addOn['modules'] as $module)<li>{{ $module }}</li>@endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    <h2>Where your data lives</h2>
    <p>{{ $residency }} Every company has its own database; a company's export is one click on its subscription page.</p>
@endsection
