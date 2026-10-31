@extends('layouts.public')
@section('title', 'Create your company')
@section('body')
    <h1>Create your company</h1>
    <p class="muted">A {{ \App\Support\Plans::trialDays() }}-day trial of {{ \App\Support\Plans::label(\App\Support\Plans::trialPlan()) }}, no card. We set nothing up until you confirm your email. Hosted in Pakistan, backed up in Pakistan.</p>

    <form method="post" action="{{ route('signup.store') }}" class="card" autocomplete="on">
        @csrf

        <label for="name">Your name</label>
        <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
        @error('name')<p class="error">{{ $message }}</p>@enderror

        <label for="email">Work email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email">
        @error('email')<p class="error">{{ $message }}</p>@enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        @error('password')<p class="error">{{ $message }}</p>@enderror

        <label for="company">Company name</label>
        <input id="company" name="company" value="{{ old('company') }}" required maxlength="120" autocomplete="organization">
        @error('company')<p class="error">{{ $message }}</p>@enderror

        <label for="profile">What kind of business</label>
        <select id="profile" name="profile">
            <option value="">Not sure yet</option>
            @foreach($profiles as $key => $label)
                <option value="{{ $key }}" @selected(old('profile') === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="muted">Decides what you start with. Everything else can be switched on later.</p>
        @error('profile')<p class="error">{{ $message }}</p>@enderror

        {{-- Honeypot: people never see it, bots fill it. The controller treats a value as a hit. --}}
        <div class="hp" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit">Send me the confirmation link</button>
    </form>

    <p class="muted">Already have an account? <a href="{{ \Filament\Facades\Filament::getPanel('admin')->getLoginUrl() }}">Sign in</a>.</p>
@endsection
