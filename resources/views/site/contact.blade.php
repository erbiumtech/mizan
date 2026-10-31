@extends('site.layout')
@section('body')
    <h1>Contact</h1>
    <p class="lede">A question about the product, the price or the trial. A person answers.</p>

    <div class="grid">
        <form method="post" action="{{ route('site.contact.send') }}" class="card" autocomplete="on">
            @csrf

            <label for="name">Your name</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
            @error('name')<p class="error">{{ $message }}</p>@enderror

            <label for="email">Work email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email">
            @error('email')<p class="error">{{ $message }}</p>@enderror

            <label for="company">Company</label>
            <input id="company" name="company" value="{{ old('company') }}" maxlength="120" autocomplete="organization">
            @error('company')<p class="error">{{ $message }}</p>@enderror

            <label for="message">Message</label>
            <textarea id="message" name="message" rows="6" required minlength="10" maxlength="4000">{{ old('message') }}</textarea>
            @error('message')<p class="error">{{ $message }}</p>@enderror

            {{-- Honeypot: people never see it, bots fill it. The controller treats a value as a hit. --}}
            <div class="hp" aria-hidden="true">
                <label for="website">Website</label>
                <input id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <p style="margin:1.25rem 0 0"><button class="btn" type="submit">Send</button></p>
        </form>

        @if($settings['contact_email'] || $settings['contact_phone'] || $settings['address'])
            <div class="card">
                <h3>Or reach us directly</h3>
                @if($settings['contact_email'])<p><a href="mailto:{{ $settings['contact_email'] }}">{{ $settings['contact_email'] }}</a></p>@endif
                @if($settings['contact_phone'])<p>{{ $settings['contact_phone'] }}</p>@endif
                @if($settings['address'])<p class="muted" style="white-space:pre-line">{{ $settings['address'] }}</p>@endif
            </div>
        @endif
    </div>
@endsection
